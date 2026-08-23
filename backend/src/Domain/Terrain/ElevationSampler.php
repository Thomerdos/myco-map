<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

use App\Domain\Geo\BoundingBox;
use App\Domain\Geo\Coordinates;

interface ElevationSampler
{
    /**
     * Makes the elevation data for an area locally available.
     *
     * @return int number of source tiles or files involved
     */
    public function prepare(BoundingBox $bounds): int;

    public function elevationAt(Coordinates $point): ?float;
}
