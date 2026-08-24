<?php

declare(strict_types=1);

namespace App\Application\Cartography;

use App\Domain\Geo\BoundingBox;
use App\Domain\Geo\SurveyArea;
use App\Domain\Mycology\FlushClock;
use App\Domain\Mycology\SeasonAssessment;
use App\Domain\Mycology\ScoringMode;
use App\Domain\Mycology\SpeciesCatalog;
use App\Domain\Mycology\SuitabilityCalculator;
use App\Domain\Terrain\TerrainCellStore;
use App\Domain\Weather\WeatherSource;

/**
 * Coarse day-by-day score outlook for the next two weeks, so the UI can show a strip of
 * "Projection au …" without recomputing a full fine grid fourteen times.
 */
final readonly class ProjectScoreHorizon
{
    public const HORIZON_DAYS = 14;
    private const MAX_CELLS = 4_000;

    public function __construct(
        private SurveyArea $area,
        private TerrainCellStore $cellStore,
        private WeatherSource $weatherSource,
        private SpeciesCatalog $speciesCatalog,
        private SuitabilityCalculator $calculator,
    ) {
    }

    /**
     * @return array{
     *   species: string,
     *   from: string,
     *   to: string,
     *   days: list<array<string, mixed>>
     * }
     */
    public function __invoke(
        BoundingBox $viewport,
        string $speciesId,
        \DateTimeImmutable $from,
    ): array {
        $timezone = new \DateTimeZone('Europe/Paris');
        $from = $from->setTimezone($timezone)->setTime(12, 0);

        $grid = $this->cellStore->storedGrid() ?? $this->area->grid();
        $window = $grid->windowFor($viewport, self::MAX_CELLS);
        if ($window === null) {
            return [
                'species' => $speciesId,
                'from' => $from->format('Y-m-d'),
                'to' => $from->modify(sprintf('+%d days', self::HORIZON_DAYS))->format('Y-m-d'),
                'days' => [],
            ];
        }

        $species = $this->speciesCatalog->get($speciesId);
        $cells = iterator_to_array($this->cellStore->readWindow($window), false);

        $days = [];
        for ($offset = 0; $offset <= self::HORIZON_DAYS; $offset++) {
            $date = $from->modify(sprintf('+%d days', $offset));
            $season = new SeasonAssessment($species, $date);
            $weatherField = $this->weatherSource->fieldFor($this->area->bounds, $date);
            $weatherAvg = $weatherField->average();

            $sum = 0.0;
            $count = 0;
            $best = null;
            foreach ($cells as $entry) {
                $profile = $entry['profile'];
                $weather = $weatherField->at($profile->coordinates);
                $score = $this->calculator->evaluate(
                    $species,
                    $profile,
                    $weather,
                    $season,
                    ScoringMode::Moment,
                );
                $sum += $score;
                $count++;
                if ($best === null || $score > $best) {
                    $best = $score;
                }
            }

            $days[] = [
                'date' => $date->format('Y-m-d'),
                'offset' => $offset,
                'label' => $offset === 0
                    ? 'Aujourd\'hui'
                    : sprintf('J+%d', $offset),
                'average' => $count > 0 ? round($sum / $count, 1) : null,
                'best' => $best !== null ? round($best, 1) : null,
                'inSeason' => $season->isInSeason(),
                'weather' => FlushClock::decorate($weatherAvg, $species)
                    + ['degraded' => $weatherField->degraded],
            ];
        }

        return [
            'species' => $speciesId,
            'from' => $from->format('Y-m-d'),
            'to' => $from->modify(sprintf('+%d days', self::HORIZON_DAYS))->format('Y-m-d'),
            'days' => $days,
        ];
    }
}
