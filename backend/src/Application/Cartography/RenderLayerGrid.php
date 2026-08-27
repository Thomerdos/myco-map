<?php

declare(strict_types=1);

namespace App\Application\Cartography;

use App\Domain\Cartography\LayerLegendFactory;
use App\Domain\Cartography\LayerValueResolver;
use App\Domain\Cartography\MapLayer;
use App\Domain\Geo\BoundingBox;
use App\Domain\Geo\SurveyArea;
use App\Domain\Mycology\FlushClock;
use App\Domain\Mycology\SeasonAssessment;
use App\Domain\Mycology\ScoringMode;
use App\Domain\Mycology\SpeciesCatalog;
use App\Domain\Mycology\SuitabilityCalculator;
use App\Domain\Mycology\SuitabilityLevel;
use App\Domain\Terrain\AccessThreshold;
use App\Domain\Terrain\TerrainCellStore;
use App\Domain\Terrain\TerrainProfile;
use App\Domain\Weather\WeatherSource;

/**
 * Turns a viewport request into a dense value raster the client can paint directly,
 * which is what makes the map read as a continuous surface rather than a grid.
 */
final readonly class RenderLayerGrid
{
    private const MAX_SECTORS = 24;

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
        $mode = $query->scoringMode;
        $weatherField = $this->weatherSource->fieldFor($this->area->bounds, $query->date);

        $columns = $window->columns();
        $rows = $window->rows();
        $values = array_fill(0, $columns * $rows, null);

        $hot = [];
        $sum = 0.0;
        $count = 0;
        $best = null;

        foreach ($this->cellStore->readWindow($window) as $entry) {
            $profile = $entry['profile'];
            $weather = $weatherField->at($profile->coordinates);
            $potential = $this->calculator->evaluate($species, $profile, $weather, $season, $mode);
            $waterMillimetres = $query->layer === MapLayer::Weather
                ? $this->calculator->waterSupplyMillimetres($weather)
                : 0.0;

            $value = $this->valueResolver->resolve($query->layer, $profile, $weather, $potential, $waterMillimetres);
            if ($value === null) {
                continue;
            }

            if ($query->layer === MapLayer::Access && !AccessThreshold::isAccessible((int) $value)) {
                continue;
            }

            $hideUnreachable = $query->accessibleOnly
                && $query->layer === MapLayer::Potential
                && !AccessThreshold::isAccessible($profile->accessDistanceMeters);
            if ($hideUnreachable) {
                continue;
            }

            $localColumn = $window->localColumn($entry['column']);
            $localRow = $window->localRow($entry['row']);
            if ($localColumn < 0 || $localRow < 0 || $localColumn >= $columns || $localRow >= $rows) {
                continue;
            }

            // Image rows run north to south, grid rows run south to north.
            $index = ($rows - 1 - $localRow) * $columns + $localColumn;
            $values[$index] = round($value, 1);

            $sum += $value;
            $count++;
            if ($best === null || $value > $best) {
                $best = $value;
            }

            if ($query->layer === MapLayer::Potential && $potential >= SuitabilityLevel::HOTSPOT_THRESHOLD) {
                $hot[$index] = ['profile' => $profile, 'score' => $potential];
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
            legend: $this->legendFactory->create($query->layer, $mode),
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
                'hotspotThreshold' => SuitabilityLevel::HOTSPOT_THRESHOLD,
            ],
            sectors: $this->extractSectors($hot, $columns, $rows, $effectiveCellSize),
            weather: FlushClock::decorate($weatherField->at($query->viewport->center()), $species, $query->date)
                + ['degraded' => $weatherField->degraded],
            species: [
                'id' => $species->id,
                'name' => $species->commonName,
                'scientificName' => $species->scientificName,
                'summary' => $species->summary,
                'hostTrees' => $species->hostTrees,
                'inSeason' => $season->isInSeason(),
                'seasonGate' => $season->gateMessage(),
                'activeWindow' => $season->activeWindow?->toArray(),
                'nextWindow' => $season->nextWindow?->toArray(),
                'harvestWindows' => array_map(
                    static fn ($window): array => $window->toArray(),
                    $species->harvestWindows
                ),
            ],
            scoringMode: $mode,
            asOfDate: $query->date->format('Y-m-d'),
            sparseNulls: $query->layer === MapLayer::Access
                || $query->layer === MapLayer::StandDensity
                || ($query->accessibleOnly && $query->layer === MapLayer::Potential),
        );
    }

    /**
     * Groups cells ≥ 90 into connected patches. Ranking individual cells is meaningless
     * on the score plateaus the model produces; area is the honest unit.
     *
     * @param array<int, array{profile: TerrainProfile, score: float}> $hot
     * @return list<array<string, mixed>>
     */
    private function extractSectors(array $hot, int $columns, int $rows, int $cellSizeMeters): array
    {
        if ($hot === []) {
            return [];
        }

        $cellAreaHa = ($cellSizeMeters * $cellSizeMeters) / 10_000;
        $minCells = max(2, (int) ceil(1.0 / max(0.01, $cellAreaHa)));
        $visited = [];
        $sectors = [];

        foreach (array_keys($hot) as $start) {
            if (isset($visited[$start])) {
                continue;
            }

            $stack = [$start];
            $members = [];
            while ($stack !== []) {
                $index = array_pop($stack);
                if (isset($visited[$index]) || !isset($hot[$index])) {
                    continue;
                }
                $visited[$index] = true;
                $members[] = $index;

                $row = intdiv($index, $columns);
                $column = $index % $columns;
                foreach ([[0, 1], [0, -1], [1, 0], [-1, 0]] as [$dRow, $dColumn]) {
                    $nextRow = $row + $dRow;
                    $nextColumn = $column + $dColumn;
                    if ($nextRow < 0 || $nextColumn < 0 || $nextRow >= $rows || $nextColumn >= $columns) {
                        continue;
                    }
                    $neighbour = $nextRow * $columns + $nextColumn;
                    if (isset($hot[$neighbour]) && !isset($visited[$neighbour])) {
                        $stack[] = $neighbour;
                    }
                }
            }

            if (\count($members) < $minCells) {
                continue;
            }

            $bestIndex = $members[0];
            $sumScore = 0.0;
            $sumLat = 0.0;
            $sumLng = 0.0;
            $minScore = $hot[$bestIndex]['score'];
            $maxScore = $minScore;
            $minAccess = $hot[$bestIndex]['profile']->accessDistanceMeters;
            foreach ($members as $index) {
                $score = $hot[$index]['score'];
                $sumScore += $score;
                $point = $hot[$index]['profile']->coordinates;
                $sumLat += $point->latitude;
                $sumLng += $point->longitude;
                $minAccess = min($minAccess, $hot[$index]['profile']->accessDistanceMeters);
                if ($score > $maxScore) {
                    $maxScore = $score;
                    $bestIndex = $index;
                }
                if ($score < $minScore) {
                    $minScore = $score;
                }
            }

            $profile = $hot[$bestIndex]['profile'];
            $n = \count($members);

            $sectors[] = [
                'lat' => round($sumLat / $n, 5),
                'lng' => round($sumLng / $n, 5),
                'cells' => $n,
                'areaHa' => round($n * $cellAreaHa, 1),
                'minScore' => round($minScore, 1),
                'maxScore' => round($maxScore, 1),
                'average' => round($sumScore / $n, 1),
                'elevation' => $profile->elevationMeters,
                'exposure' => $profile->exposure()->cardinal(),
                'cover' => $profile->cover->label(),
                'hostTree' => $profile->hostTree->label(),
                'hostTreeCode' => $profile->hostTree->value,
                'canopy' => $profile->canopy->shortLabel(),
                'canopyCode' => $profile->canopy->value,
                'canopyCover' => $profile->canopyCoverPercent,
                'canopyHeight' => $profile->canopyHeightMeters,
                'accessMeters' => $minAccess,
            ];
        }

        usort(
            $sectors,
            static function (array $a, array $b): int {
                $byArea = $b['areaHa'] <=> $a['areaHa'];

                return $byArea !== 0 ? $byArea : $b['maxScore'] <=> $a['maxScore'];
            },
        );

        return array_slice($sectors, 0, self::MAX_SECTORS);
    }
}
