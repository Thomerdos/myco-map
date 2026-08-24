<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

use App\Domain\Geo\BoundingBox;

interface GeologySource
{
    /** @return iterable<int, list<GeologyPolygon>> */
    public function geologyPolygons(BoundingBox $bounds): iterable;
}
