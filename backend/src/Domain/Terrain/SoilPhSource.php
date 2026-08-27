<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

use App\Domain\Geo\Grid;

/**
 * Continuous soil pH (H₂O) on the study lattice. Values are pH × 10 as integers
 * (65 = 6.5); −1 marks unknown cells.
 */
interface SoilPhSource
{
    public function isAvailable(): bool;

    /** @return \SplFixedArray<int> */
    public function sample(Grid $grid): \SplFixedArray;
}
