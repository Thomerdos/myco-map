<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

use App\Domain\Geo\Coordinates;
use App\Domain\Geo\Grid;
use App\Domain\Geo\GridWindow;

interface TerrainCellStore
{
    public function prepareStorage(): void;

    /**
     * Bulk insert of precomputed rasters. Rows are plain scalar tuples — no
     * TerrainProfile — so the writer can stream ~2 M cells without flooding the GC.
     *
     * @param \Traversable<int, array{
     *     row: int,
     *     column: int,
     *     latitude: float,
     *     longitude: float,
     *     elevation: int,
     *     slope: float,
     *     aspect: float,
     *     curvature: float,
     *     cover: int,
     *     edge_distance: int,
     *     water_distance: int,
     *     geology: int,
     *     access_distance: int,
     *     canopy_cover: ?int,
     *     soil_ph: ?float,
     *     park: int,
     *     path: int
     * }> $cells
     */
    public function replaceAll(Grid $grid, \Traversable $cells): int;

    public function storedGrid(): ?Grid;

    public function isEmpty(): bool;

    /**
     * @return iterable<int, array{column: int, row: int, profile: TerrainProfile, park?: int, path?: int}>
     */
    public function readWindow(GridWindow $window): iterable;

    public function findNearest(Coordinates $point): ?TerrainProfile;
}
