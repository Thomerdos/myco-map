<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

use App\Domain\Geo\Grid;

/**
 * Canopy height in whole metres (LIDAR HD CHM). Unknown cells are -1.
 */
interface CanopyHeightSource
{
    public function isAvailable(): bool;

    /**
     * @return \SplFixedArray<int>
     */
    public function sample(Grid $grid): \SplFixedArray;
}
