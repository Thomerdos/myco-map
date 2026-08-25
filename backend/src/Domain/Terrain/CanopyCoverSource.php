<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

use App\Domain\Geo\Grid;

/**
 * Continuous tree-cover percent (0–100) at grid cells. Missing raster → all unknown.
 */
interface CanopyCoverSource
{
    public function isAvailable(): bool;

    /**
     * Same indexing as {@see Grid::offset()}. Unknown cells are -1.
     *
     * @return \SplFixedArray<int>
     */
    public function sample(Grid $grid): \SplFixedArray;
}
