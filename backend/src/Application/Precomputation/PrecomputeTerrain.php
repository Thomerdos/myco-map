<?php

declare(strict_types=1);

namespace App\Application\Precomputation;

use App\Domain\Geo\Grid;
use App\Domain\Geo\SurveyArea;
use App\Domain\Terrain\AccessWay;
use App\Domain\Terrain\CanopyClosure;
use App\Domain\Terrain\CanopyCoverSource;
use App\Domain\Terrain\ElevationSampler;
use App\Domain\Terrain\ForestCover;
use App\Domain\Terrain\GeologySource;
use App\Domain\Terrain\HostTree;
use App\Domain\Terrain\LandCoverSource;
use App\Domain\Terrain\StandCode;
use App\Domain\Terrain\Substrate;
use App\Domain\Terrain\TerrainCellStore;
use App\Domain\Terrain\TerrainProfile;
use App\Infrastructure\Raster\ChamferDistance;
use App\Infrastructure\Raster\PathNetworkAccess;
use App\Infrastructure\Raster\PolygonRasterizer;
use App\Infrastructure\Raster\TerrainDerivatives;

/**
 * Builds the static half of the model once: elevation derivatives, forest cover,
 * geology, access and tree-cover density for every cell. Weather stays dynamic
 * at query time.
 */
final readonly class PrecomputeTerrain
{
    public function __construct(
        private SurveyArea $area,
        private ElevationSampler $elevationSampler,
        private LandCoverSource $landCover,
        private GeologySource $geology,
        private CanopyCoverSource $canopyCoverSource,
        private TerrainCellStore $cellStore,
        private PolygonRasterizer $rasterizer,
        private ChamferDistance $distance,
        private PathNetworkAccess $pathAccess,
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
        [$geology, $geologyCount] = $this->rasterizeGeology($grid, $progress);
        [$water, $waterCount] = $this->rasterizeWater($grid, $progress);
        [$park, $path, $accessWays] = $this->rasterizeAccess($grid, $progress);
        [$canopyCover, $canopyCoverCells] = $this->sampleCanopyCover($grid, $progress);

        $progress->stageStarted('Distances aux lisières et à l\'eau');
        $insideForest = $this->distance->compute(
            $cover,
            $grid,
            static fn (int $value): bool => StandCode::isOpenGround($value),
        );
        $towardsForest = $this->distance->compute(
            $cover,
            $grid,
            static fn (int $value): bool => !StandCode::isOpenGround($value),
        );
        $towardsWater = $this->distance->compute(
            $water,
            $grid,
            static fn (int $value): bool => $value === 1,
        );
        $progress->stageFinished('Distances aux lisières et à l\'eau');

        $progress->stageStarted('Accès parking + chemins');
        $access = $this->pathAccess->compute($park, $path, $derived['slope'], $grid);
        $progress->stageFinished('Accès parking + chemins');

        $this->cellStore->prepareStorage();

        $progress->stageStarted('Écriture de la base précalculée');
        $written = $this->cellStore->replaceAll(
            $grid,
            $this->buildCells($grid, $elevation, $derived, $cover, $geology, $insideForest, $towardsForest, $towardsWater, $access, $canopyCover, $park, $path, $progress),
        );
        $progress->stageFinished('Écriture de la base précalculée');

        return new PrecomputationReport(
            cells: $written,
            columns: $grid->columns,
            rows: $grid->rows,
            cellSizeMeters: $grid->cellSizeMeters,
            elevationTiles: $tiles,
            forestPolygons: $forestCount,
            geologyPolygons: $geologyCount,
            waterFeatures: $waterCount,
            accessWays: $accessWays,
            canopyCoverCells: $canopyCoverCells,
            unavailableChunks: $this->landCover->unavailableChunks(),
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
        $stage = 'Couvert forestier';
        $progress->stageStarted($stage);

        /** @var \SplFixedArray<int> $cover */
        $cover = new \SplFixedArray($grid->cellCount());
        $openGround = StandCode::pack(ForestCover::Open, HostTree::Unknown, CanopyClosure::Unknown);
        for ($i = 0, $total = $grid->cellCount(); $i < $total; $i++) {
            $cover[$i] = $openGround;
        }

        $polygonCount = 0;
        foreach ($this->landCover->forestPolygons($grid->bounds) as $chunk) {
            foreach ($chunk as $polygon) {
                $polygonCount++;
                foreach ($polygon->outerRings as $ring) {
                    $this->rasterizer->fillRing($cover, $grid, $ring, $polygon->standCode());
                }
                foreach ($polygon->innerRings as $ring) {
                    $this->rasterizer->fillRing($cover, $grid, $ring, $openGround);
                }
            }
            $progress->stageAdvanced($stage, $polygonCount, 0);
        }

        $progress->stageFinished($stage);

        return [$cover, $polygonCount];
    }

    /** @return array{0: \SplFixedArray<int>, 1: int} */
    private function rasterizeGeology(Grid $grid, PrecomputationProgress $progress): array
    {
        $stage = 'Géologie / substrat (BRGM)';
        $progress->stageStarted($stage);

        /** @var \SplFixedArray<int> $geology */
        $geology = new \SplFixedArray($grid->cellCount());
        $unknown = Substrate::Unknown->value;
        for ($i = 0, $total = $grid->cellCount(); $i < $total; $i++) {
            $geology[$i] = $unknown;
        }

        $polygonCount = 0;
        foreach ($this->geology->geologyPolygons($grid->bounds) as $chunk) {
            foreach ($chunk as $polygon) {
                $polygonCount++;
                foreach ($polygon->outerRings as $ring) {
                    $this->rasterizer->fillRing($geology, $grid, $ring, $polygon->substrate->value);
                }
                foreach ($polygon->innerRings as $ring) {
                    $this->rasterizer->fillRing($geology, $grid, $ring, $unknown);
                }
            }
            $progress->stageAdvanced($stage, $polygonCount, 0);
        }

        $progress->stageFinished($stage);

        return [$geology, $polygonCount];
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

    /** @return array{0: \SplFixedArray<int>, 1: \SplFixedArray<int>, 2: int} */
    private function rasterizeAccess(Grid $grid, PrecomputationProgress $progress): array
    {
        $stage = 'Routes, parkings et chemins (OpenStreetMap)';
        $progress->stageStarted($stage);

        $total = $grid->cellCount();
        /** @var \SplFixedArray<int> $park */
        $park = new \SplFixedArray($total);
        /** @var \SplFixedArray<int> $path */
        $path = new \SplFixedArray($total);
        for ($i = 0; $i < $total; $i++) {
            $park[$i] = 0;
            $path[$i] = 0;
        }

        $wayCount = 0;
        foreach ($this->landCover->accessWays($grid->bounds) as $chunk) {
            foreach ($chunk as $way) {
                $wayCount++;
                if ($way->parkable) {
                    $this->stampAccess($park, $grid, $way);
                }
                if ($way->walkable) {
                    $this->stampAccess($path, $grid, $way);
                }
            }
            $progress->stageAdvanced($stage, $wayCount, 0);
        }

        $progress->stageFinished($stage);

        return [$park, $path, $wayCount];
    }

    /** @return array{0: \SplFixedArray<int>, 1: int} */
    private function sampleCanopyCover(Grid $grid, PrecomputationProgress $progress): array
    {
        $stage = 'Taux de couvert (Copernicus TCD)';
        $progress->stageStarted($stage);

        $canopyCover = $this->canopyCoverSource->sample($grid);
        $known = 0;
        for ($i = 0, $total = $grid->cellCount(); $i < $total; $i++) {
            if ($canopyCover[$i] >= 0) {
                $known++;
            }
        }

        $progress->stageFinished($stage);

        return [$canopyCover, $known];
    }

    private function stampAccess(\SplFixedArray $raster, Grid $grid, AccessWay $way): void
    {
        if ($way->isArea && \count($way->points) >= 3) {
            $this->rasterizer->fillRing($raster, $grid, $way->points, 1);

            return;
        }
        if (\count($way->points) === 1) {
            $this->rasterizer->stampPoint($raster, $grid, $way->points[0], 1);

            return;
        }
        if (\count($way->points) >= 2) {
            $this->rasterizer->stampPolyline($raster, $grid, $way->points, 1);
        }
    }

    /**
     * @param \SplFixedArray<float> $elevation
     * @param array{slope: \SplFixedArray<float>, aspect: \SplFixedArray<float>, curvature: \SplFixedArray<float>} $derived
     * @param \SplFixedArray<int> $cover
     * @param \SplFixedArray<int> $geology
     * @param \SplFixedArray<int> $insideForest
     * @param \SplFixedArray<int> $towardsForest
     * @param \SplFixedArray<int> $towardsWater
     * @param \SplFixedArray<int> $access
     * @param \SplFixedArray<int> $canopyCover
     * @param \SplFixedArray<int> $park
     * @param \SplFixedArray<int> $path
     * @return \Generator<int, array{column: int, row: int, profile: TerrainProfile, park: int, path: int}>
     */
    private function buildCells(
        Grid $grid,
        \SplFixedArray $elevation,
        array $derived,
        \SplFixedArray $cover,
        \SplFixedArray $geology,
        \SplFixedArray $insideForest,
        \SplFixedArray $towardsForest,
        \SplFixedArray $towardsWater,
        \SplFixedArray $access,
        \SplFixedArray $canopyCover,
        \SplFixedArray $park,
        \SplFixedArray $path,
        PrecomputationProgress $progress,
    ): \Generator {
        $stage = 'Écriture de la base précalculée';
        $total = $grid->cellCount();

        for ($row = 0; $row < $grid->rows; $row++) {
            $rowOffset = $row * $grid->columns;

            for ($column = 0; $column < $grid->columns; $column++) {
                $index = $rowOffset + $column;
                $packed = $cover[$index];

                $edgeDistance = StandCode::isOpenGround($packed)
                    ? -$towardsForest[$index]
                    : $insideForest[$index];

                yield [
                    'column' => $column,
                    'row' => $row,
                    'park' => $park[$index],
                    'path' => $path[$index],
                    'profile' => new TerrainProfile(
                        coordinates: $grid->coordinatesAt($column, $row),
                        elevationMeters: (int) round($elevation[$index]),
                        slopeDegrees: round($derived['slope'][$index], 2),
                        aspectDegrees: round($derived['aspect'][$index], 1),
                        curvature: round($derived['curvature'][$index], 3),
                        cover: StandCode::cover($packed),
                        edgeDistanceMeters: $edgeDistance,
                        waterDistanceMeters: $towardsWater[$index],
                        hostTree: StandCode::host($packed),
                        canopy: StandCode::canopy($packed),
                        substrate: Substrate::tryFrom($geology[$index]) ?? Substrate::Unknown,
                        accessDistanceMeters: $access[$index],
                        canopyCoverPercent: $canopyCover[$index] >= 0 ? $canopyCover[$index] : null,
                    ),
                ];
            }

            if ($row % 40 === 0) {
                $progress->stageAdvanced($stage, $rowOffset, $total);
            }
        }
    }
}
