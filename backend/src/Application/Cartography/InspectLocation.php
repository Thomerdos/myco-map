<?php

declare(strict_types=1);

namespace App\Application\Cartography;

use App\Domain\Geo\Coordinates;
use App\Domain\Geo\SurveyArea;
use App\Domain\Mycology\FlushClock;
use App\Domain\Mycology\SeasonAssessment;
use App\Domain\Mycology\ScoringMode;
use App\Domain\Mycology\Species;
use App\Domain\Mycology\SpeciesCatalog;
use App\Domain\Mycology\SuitabilityCalculator;
use App\Domain\Terrain\AccessWalk;
use App\Domain\Terrain\TerrainCellStore;
use App\Domain\Terrain\TerrainProfile;
use App\Domain\Weather\WeatherSource;

/**
 * Full, explainable report for one point, used when the user clicks the map.
 */
final readonly class InspectLocation
{
    public function __construct(
        private SurveyArea $area,
        private TerrainCellStore $cellStore,
        private WeatherSource $weatherSource,
        private SpeciesCatalog $speciesCatalog,
        private SuitabilityCalculator $calculator,
        private TraceAccessWalk $traceAccessWalk,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function __invoke(
        Coordinates $point,
        string $speciesId,
        \DateTimeImmutable $date,
        ScoringMode $mode = ScoringMode::Moment,
    ): ?array {
        $profile = $this->cellStore->findNearest($point);
        if ($profile === null) {
            return null;
        }

        $weather = $this->weatherSource->fieldFor($this->area->bounds, $date)->at($profile->coordinates);

        $reports = [];
        foreach ($this->speciesCatalog->all() as $species) {
            $season = new SeasonAssessment($species, $date);
            $score = $this->calculator->score($species, $profile, $weather, $season, $mode);
            $reports[$species->id] = [
                'id' => $species->id,
                'name' => $species->commonName,
                'score' => round($score->value, 1),
                'level' => $score->level->value,
                'levelLabel' => $score->level->label(),
                'inSeason' => $season->isInSeason(),
            ];
        }

        $species = $this->speciesCatalog->get($speciesId);
        $season = new SeasonAssessment($species, $date);
        $score = $this->calculator->score($species, $profile, $weather, $season, $mode);

        return [
            'coordinates' => [
                'lat' => round($profile->coordinates->latitude, 5),
                'lng' => round($profile->coordinates->longitude, 5),
            ],
            'terrain' => [
                'elevation' => $profile->elevationMeters,
                'slope' => round($profile->slopeDegrees, 1),
                'aspect' => round($profile->aspectDegrees),
                'exposure' => $profile->exposure()->cardinal(),
                'coolness' => round($profile->exposure()->coolness(), 2),
                'curvature' => round($profile->curvature, 2),
                'cover' => $profile->cover->label(),
                'coverCode' => $profile->cover->value,
                'hostTree' => $profile->hostTree->label(),
                'hostTreeCode' => $profile->hostTree->value,
                'canopy' => $profile->canopy->label(),
                'canopyCode' => $profile->canopy->value,
                'canopyCover' => $profile->canopyCoverPercent,
                'edgeDistance' => $profile->edgeDistanceMeters,
                'waterDistance' => $profile->waterDistanceMeters,
                'accessDistance' => $profile->accessDistanceMeters,
                'moisture' => round($profile->moistureIndex() * 100),
                'geology' => $profile->substrate->label(),
                'geologyCode' => $profile->substrate->value,
            ],
            'weather' => FlushClock::decorate($weather, $species, $date),
            'scoringMode' => $mode->value,
            'asOfDate' => $date->format('Y-m-d'),
            'species' => [
                'id' => $species->id,
                'name' => $species->commonName,
                'scientificName' => $species->scientificName,
                'summary' => $species->summary,
                'hostTrees' => $species->hostTrees,
            ],
            'score' => round($score->value, 1),
            'level' => $score->level->value,
            'levelLabel' => $score->level->label(),
            'inSeason' => $season->isInSeason(),
            'seasonGate' => $season->gateMessage(),
            'activeWindow' => $season->activeWindow?->toArray(),
            'nextWindow' => $season->nextWindow?->toArray(),
            'breakdown' => array_map(
                static fn ($criterion): array => $criterion->toArray(),
                $score->breakdown
            ),
            'drivers' => array_map(
                static fn ($criterion): array => $criterion->toArray(),
                $score->drivers()
            ),
            'allSpecies' => array_values($reports),
            'horizon' => $this->horizonFor($species, $profile, $mode),
            'accessWalk' => $this->accessWalkFor($profile),
        ];
    }

    /**
     * Day-by-day score on this cell for today … today+HORIZON_DAYS (Europe/Paris).
     * Empty in habitat mode: weather and phenology are ignored, so every day would match.
     *
     * @return list<array<string, mixed>>
     */
    private function horizonFor(Species $species, TerrainProfile $profile, ScoringMode $mode): array
    {
        if ($mode !== ScoringMode::Moment) {
            return [];
        }

        $timezone = new \DateTimeZone('Europe/Paris');
        $from = (new \DateTimeImmutable('today', $timezone))->setTime(12, 0);
        $days = [];

        for ($offset = 0; $offset <= ProjectScoreHorizon::HORIZON_DAYS; $offset++) {
            $date = $from->modify(sprintf('+%d days', $offset));
            $season = new SeasonAssessment($species, $date);
            $weather = $this->weatherSource->fieldFor($this->area->bounds, $date)->at($profile->coordinates);
            $value = $this->calculator->evaluate($species, $profile, $weather, $season, ScoringMode::Moment);

            $days[] = [
                'date' => $date->format('Y-m-d'),
                'offset' => $offset,
                'label' => $offset === 0
                    ? 'Aujourd\'hui'
                    : sprintf('J+%d', $offset),
                'score' => round($value, 1),
                'inSeason' => $season->isInSeason(),
                'weather' => FlushClock::decorate($weather, $species, $date),
            ];
        }

        return $days;
    }

    /** @return array<string, mixed> */
    private function accessWalkFor(TerrainProfile $profile): array
    {
        try {
            return $this->traceAccessWalk->for($profile)->toArray();
        } catch (\Throwable) {
            return AccessWalk::fromMeters($profile->accessDistanceMeters)->toArray();
        }
    }
}
