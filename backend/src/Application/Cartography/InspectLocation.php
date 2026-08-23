<?php

declare(strict_types=1);

namespace App\Application\Cartography;

use App\Domain\Geo\Coordinates;
use App\Domain\Geo\SurveyArea;
use App\Domain\Mycology\SeasonAssessment;
use App\Domain\Mycology\SpeciesCatalog;
use App\Domain\Mycology\SuitabilityCalculator;
use App\Domain\Terrain\TerrainCellStore;
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
    ) {
    }

    /** @return array<string, mixed>|null */
    public function __invoke(Coordinates $point, string $speciesId, \DateTimeImmutable $date): ?array
    {
        $profile = $this->cellStore->findNearest($point);
        if ($profile === null) {
            return null;
        }

        $weather = $this->weatherSource->fieldFor($this->area->bounds)->at($profile->coordinates);

        $reports = [];
        foreach ($this->speciesCatalog->all() as $species) {
            $season = new SeasonAssessment($species, $date);
            $score = $this->calculator->score($species, $profile, $weather, $season);
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
        $score = $this->calculator->score($species, $profile, $weather, $season);

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
                'edgeDistance' => $profile->edgeDistanceMeters,
                'waterDistance' => $profile->waterDistanceMeters,
                'moisture' => round($profile->moistureIndex() * 100),
            ],
            'weather' => $weather->toArray(),
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
        ];
    }
}
