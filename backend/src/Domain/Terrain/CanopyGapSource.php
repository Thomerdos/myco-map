<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

use App\Domain\Geo\Grid;

/**
 * Gap fraction (0–100 %) derived from fine LIDAR HD MNH inside each 50 m cell.
 * Values are percent of sub-pixels below the gap height threshold; -1 = unknown.
 */
interface CanopyGapSource
{
    public function isAvailable(): bool;

    /**
     * @return \SplFixedArray<int> length = grid cell count; -1 unknown, else 0–100
     */
    public function sample(Grid $grid): \SplFixedArray;
}
