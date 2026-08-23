<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

use App\Domain\Geo\Coordinates;
use App\Domain\Geo\Grid;
use App\Domain\Geo\GridWindow;

interface TerrainCellStore
{
    public function prepareStorage(): void;

    public function replaceAll(Grid $grid, \Traversable $cells): int;

    public function storedGrid(): ?Grid;

    public function isEmpty(): bool;

    /**
     * @return iterable<int, array{column: int, row: int, profile: TerrainProfile}>
     */
    public function readWindow(GridWindow $window): iterable;

    public function findNearest(Coordinates $point): ?TerrainProfile;
}
