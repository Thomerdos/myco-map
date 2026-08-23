<?php

declare(strict_types=1);

namespace App\Application\Precomputation;

use App\Domain\Geo\Grid;
use App\Domain\Geo\SurveyArea;
use App\Domain\Terrain\ElevationSampler;
use App\Domain\Terrain\ForestCover;
use App\Domain\Terrain\LandCoverSource;
use App\Domain\Terrain\TerrainCellStore;
use App\Domain\Terrain\TerrainProfile;
use App\Infrastructure\Raster\ChamferDistance;
use App\Infrastructure\Raster\PolygonRasterizer;
use App\Infrastructure\Raster\TerrainDerivatives;

/**
 * Builds the static half of the model once: elevation derivatives, forest cover and
 * hydrography for every cell of the survey area. Weather stays dynamic at query time.
 */
final readonly class PrecomputeTerrain
{
    public function __construct(
        private SurveyArea $area,
        private ElevationSampler $elevationSampler,
        private LandCoverSource $landCover,
        private TerrainCellStore $cellStore,
        private PolygonRasterizer $rasterizer,
        private ChamferDistance $distance,
        private TerrainDerivatives $derivatives,
    ) {
    }

    public function __invoke(PrecomputationProgress $progress): PrecomputationReport
    {
        $startedAt = microtime(true);
        $grid = $this->area->grid();

        if ($grid->cellCount() <= 0) {
            throw new \RuntimeException('La zone d\'étude ne produit aucune maille exploitable.');
        }

        $progress->stageStarted('Préparation du relief');
        $tiles = $this->elevationSampler->prepare($grid->bounds);
        $progress->stageFinished('Préparation du relief');

        $elevation = $this->sampleElevation($grid, $progress);

        $progress->stageStarted('Pente, exposition et courbure');
        $derived = $this->derivatives->compute($elevation, $grid);
        $progress->stageFinished('Pente, exposition et courbure');

        [$cover, $forestCount] = $this->rasterizeForest($grid, $progress);
        [$water, $waterCount] = $this->rasterizeWater($grid, $progress);

        $progress->stageStarted('Distances aux lisières et à l\'eau');
        $insideForest = $this->distance->compute(
            $cover,
            $grid,
            static fn (int $value): bool => $value === ForestCover::Open->value,
        );
        $towardsForest = $this->distance->compute(
            $cover,
            $grid,
            static fn (int $value): bool => $value !== ForestCover::Open->value,
        );
        $towardsWater = $this->distance->compute(
            $water,
            $grid,
            static fn (int $value): bool => $value === 1,
        );
        $progress->stageFinished('Distances aux lisières et à l\'eau');

        $this->cellStore->prepareStorage();

        $progress->stageStarted('Écriture de la base précalculée');
        $written = $this->cellStore->replaceAll(
            $grid,
            $this->buildCells($grid, $elevation, $derived, $cover, $insideForest, $towardsForest, $towardsWater, $progress),
        );
        $progress->stageFinished('Écriture de la base précalculée');

        return new PrecomputationReport(
            cells: $written,
            columns: $grid->columns,
            rows: $grid->rows,
            cellSizeMeters: $grid->cellSizeMeters,
            elevationTiles: $tiles,
            forestPolygons: $forestCount,
            waterFeatures: $waterCount,
            durationSeconds: microtime(true) - $startedAt,
        );
    }

    /** @return \SplFixedArray<float> */
    private function sampleElevation(Grid $grid, PrecomputationProgress $progress): \SplFixedArray
    {
        $stage = 'Échantillonnage des altitudes';
        $progress->stageStarted($stage);

        $total = $grid->cellCount();
        /** @var \SplFixedArray<float> $elevation */
        $elevation = new \SplFixedArray($total);

        for ($row = 0; $row < $grid->rows; $row++) {
            $latitude = $grid->latitudeAt($row);
            $rowOffset = $row * $grid->columns;

            for ($column = 0; $column < $grid->columns; $column++) {
                $elevation[$rowOffset + $column] = $this->elevationSampler->elevationAt(
                    $grid->coordinatesAt($column, $row)
                ) ?? 0.0;
            }

            if ($row % 40 === 0) {
                $progress->stageAdvanced($stage, $rowOffset, $total);
            }
        }

        $progress->stageFinished($stage);

        return $elevation;
    }

    /** @return array{0: \SplFixedArray<int>, 1: int} */
    private function rasterizeForest(Grid $grid, PrecomputationProgress $progress): array
    {
        $stage = 'Couvert forestier (OpenStreetMap)';
        $progress->stageStarted($stage);

        /** @var \SplFixedArray<int> $cover */
        $cover = new \SplFixedArray($grid->cellCount());
        $cover->setSize($grid->cellCount());
        for ($i = 0, $total = $grid->cellCount(); $i < $total; $i++) {
            $cover[$i] = ForestCover::Open->value;
        }

        $polygonCount = 0;
        foreach ($this->landCover->forestPolygons($grid->bounds) as $chunk) {
            foreach ($chunk as $polygon) {
                $polygonCount++;
                foreach ($polygon->outerRings as $ring) {
                    $this->rasterizer->fillRing($cover, $grid, $ring, $polygon->cover->value);
                }
                foreach ($polygon->innerRings as $ring) {
                    $this->rasterizer->fillRing($cover, $grid, $ring, ForestCover::Open->value);
                }
            }
            $progress->stageAdvanced($stage, $polygonCount, 0);
        }

        $progress->stageFinished($stage);

        return [$cover, $polygonCount];
    }

    /** @return array{0: \SplFixedArray<int>, 1: int} */
    private function rasterizeWater(Grid $grid, PrecomputationProgress $progress): array
    {
        $stage = 'Hydrographie (OpenStreetMap)';
        $progress->stageStarted($stage);

        /** @var \SplFixedArray<int> $water */
        $water = new \SplFixedArray($grid->cellCount());
        for ($i = 0, $total = $grid->cellCount(); $i < $total; $i++) {
            $water[$i] = 0;
        }

        $featureCount = 0;
        foreach ($this->landCover->waterFeatures($grid->bounds) as $chunk) {
            foreach ($chunk as $feature) {
                $featureCount++;
                if ($feature->isArea) {
                    $this->rasterizer->fillRing($water, $grid, $feature->points, 1);
                    continue;
                }
                $this->rasterizer->stampPolyline($water, $grid, $feature->points, 1);
            }
            $progress->stageAdvanced($stage, $featureCount, 0);
        }

        $progress->stageFinished($stage);

        return [$water, $featureCount];
    }

    /**
     * @param \SplFixedArray<float> $elevation
     * @param array{slope: \SplFixedArray<float>, aspect: \SplFixedArray<float>, curvature: \SplFixedArray<float>} $derived
     * @param \SplFixedArray<int> $cover
     * @param \SplFixedArray<int> $insideForest
     * @param \SplFixedArray<int> $towardsForest
     * @param \SplFixedArray<int> $towardsWater
     * @return \Generator<int, array{column: int, row: int, profile: TerrainProfile}>
     */
    private function buildCells(
        Grid $grid,
        \SplFixedArray $elevation,
        array $derived,
        \SplFixedArray $cover,
        \SplFixedArray $insideForest,
        \SplFixedArray $towardsForest,
        \SplFixedArray $towardsWater,
        PrecomputationProgress $progress,
    ): \Generator {
        $stage = 'Écriture de la base précalculée';
        $total = $grid->cellCount();

        for ($row = 0; $row < $grid->rows; $row++) {
            $rowOffset = $row * $grid->columns;

            for ($column = 0; $column < $grid->columns; $column++) {
                $index = $rowOffset + $column;
                $coverValue = ForestCover::from($cover[$index]);

                $edgeDistance = $coverValue === ForestCover::Open
                    ? -$towardsForest[$index]
                    : $insideForest[$index];

                yield [
                    'column' => $column,
                    'row' => $row,
                    'profile' => new TerrainProfile(
                        coordinates: $grid->coordinatesAt($column, $row),
                        elevationMeters: (int) round($elevation[$index]),
                        slopeDegrees: round($derived['slope'][$index], 2),
                        aspectDegrees: round($derived['aspect'][$index], 1),
                        curvature: round($derived['curvature'][$index], 3),
                        cover: $coverValue,
                        edgeDistanceMeters: $edgeDistance,
                        waterDistanceMeters: $towardsWater[$index],
                    ),
                ];
            }

            if ($row % 40 === 0) {
                $progress->stageAdvanced($stage, $rowOffset, $total);
            }
        }
    }
}
