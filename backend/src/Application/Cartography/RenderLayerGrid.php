<?php

declare(strict_types=1);

namespace App\Application\Cartography;

use App\Domain\Cartography\LayerLegendFactory;
use App\Domain\Cartography\LayerValueResolver;
use App\Domain\Cartography\MapLayer;
use App\Domain\Geo\BoundingBox;
use App\Domain\Geo\Coordinates;
use App\Domain\Geo\SurveyArea;
use App\Domain\Mycology\SeasonAssessment;
use App\Domain\Mycology\SpeciesCatalog;
use App\Domain\Mycology\SuitabilityCalculator;
use App\Domain\Terrain\TerrainCellStore;
use App\Domain\Weather\WeatherSource;

/**
 * Turns a viewport request into a dense value raster the client can paint directly,
 * which is what makes the map read as a continuous surface rather than a grid.
 */
final readonly class RenderLayerGrid
{
    private const HIGHLIGHT_COUNT = 8;
    private const HIGHLIGHT_SPACING_METERS = 900.0;

    public function __construct(
        private SurveyArea $area,
        private TerrainCellStore $cellStore,
        private WeatherSource $weatherSource,
        private SpeciesCatalog $speciesCatalog,
        private SuitabilityCalculator $calculator,
        private LayerValueResolver $valueResolver,
        private LayerLegendFactory $legendFactory,
    ) {
    }

    public function __invoke(LayerGridQuery $query): ?LayerGridView
    {
        $grid = $this->cellStore->storedGrid() ?? $this->area->grid();
        $window = $grid->windowFor($query->viewport, $query->maxCells);
        if ($window === null) {
            return null;
        }

        $species = $this->speciesCatalog->get($query->speciesId);
        $season = new SeasonAssessment($species, $query->date);
        $weatherField = $this->weatherSource->fieldFor($this->area->bounds);

        $columns = $window->columns();
        $rows = $window->rows();
        $values = array_fill(0, $columns * $rows, null);

        $scored = [];
        $sum = 0.0;
        $count = 0;
        $best = null;

        foreach ($this->cellStore->readWindow($window) as $entry) {
            $profile = $entry['profile'];
            $weather = $weatherField->at($profile->coordinates);

            $score = $query->layer->requiresSpecies() || $query->layer === MapLayer::Potential
                ? $this->calculator->score($species, $profile, $weather, $season)
                : null;

            $value = $this->valueResolver->resolve($query->layer, $profile, $weather, $score);
            if ($value === null) {
                continue;
            }

            $localColumn = $window->localColumn($entry['column']);
            $localRow = $window->localRow($entry['row']);
            if ($localColumn < 0 || $localRow < 0 || $localColumn >= $columns || $localRow >= $rows) {
                continue;
            }

            // Image rows run north to south, grid rows run south to north.
            $values[($rows - 1 - $localRow) * $columns + $localColumn] = round($value, 1);

            $sum += $value;
            $count++;
            if ($best === null || $value > $best) {
                $best = $value;
            }

            if ($score !== null && $score->value >= 55) {
                $scored[] = ['profile' => $profile, 'score' => $score];
            }
        }

        $effectiveCellSize = $grid->cellSizeMeters * $window->step;
        $bounds = new BoundingBox(
            south: $grid->bounds->south + $window->firstRow * $grid->latitudeStep,
            west: $grid->bounds->west + $window->firstColumn * $grid->longitudeStep,
            north: $grid->bounds->south + ($window->firstRow + $rows * $window->step) * $grid->latitudeStep,
            east: $grid->bounds->west + ($window->firstColumn + $columns * $window->step) * $grid->longitudeStep,
        );

        return new LayerGridView(
            layer: $query->layer,
            legend: $this->legendFactory->create($query->layer),
            bounds: $bounds,
            columns: $columns,
            rows: $rows,
            cellSizeMeters: $effectiveCellSize,
            values: array_values($values),
            statistics: [
                'cells' => $count,
                'average' => $count > 0 ? round($sum / $count, 1) : null,
                'best' => $best !== null ? round($best, 1) : null,
                'resolution' => $effectiveCellSize,
            ],
            highlights: $this->pickHighlights($scored),
            weather: $weatherField->at($query->viewport->center())->toArray()
                + ['degraded' => $weatherField->degraded],
            species: [
                'id' => $species->id,
                'name' => $species->commonName,
                'scientificName' => $species->scientificName,
                'summary' => $species->summary,
                'hostTrees' => $species->hostTrees,
                'inSeason' => $season->isInSeason(),
                'activeWindow' => $season->activeWindow?->toArray(),
                'nextWindow' => $season->nextWindow?->toArray(),
                'harvestWindows' => array_map(
                    static fn ($window): array => $window->toArray(),
                    $species->harvestWindows
                ),
            ],
        );
    }

    /**
     * Keeps the best cells while enforcing a minimum spacing, so the list reads as
     * distinct spots to visit rather than one hotspot repeated.
     *
     * @param list<array{profile: \App\Domain\Terrain\TerrainProfile, score: \App\Domain\Mycology\SuitabilityScore}> $scored
     * @return list<array<string, mixed>>
     */
    private function pickHighlights(array $scored): array
    {
        usort($scored, static fn (array $a, array $b): int => $b['score']->value <=> $a['score']->value);

        $selected = [];
        /** @var list<Coordinates> $chosen */
        $chosen = [];

        foreach ($scored as $candidate) {
            if (\count($selected) >= self::HIGHLIGHT_COUNT) {
                break;
            }

            $point = $candidate['profile']->coordinates;
            foreach ($chosen as $existing) {
                if ($point->distanceTo($existing) < self::HIGHLIGHT_SPACING_METERS) {
                    continue 2;
                }
            }

            $chosen[] = $point;
            $drivers = $candidate['score']->drivers(3);

            $selected[] = [
                'lat' => round($point->latitude, 5),
                'lng' => round($point->longitude, 5),
                'score' => round($candidate['score']->value, 1),
                'level' => $candidate['score']->level->value,
                'levelLabel' => $candidate['score']->level->label(),
                'elevation' => $candidate['profile']->elevationMeters,
                'exposure' => $candidate['profile']->exposure()->cardinal(),
                'cover' => $candidate['profile']->cover->label(),
                'reasons' => array_map(
                    static fn ($driver): string => $driver->explanation,
                    $drivers
                ),
            ];
        }

        return $selected;
    }
}
