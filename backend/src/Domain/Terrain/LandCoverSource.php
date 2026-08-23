<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

use App\Domain\Geo\BoundingBox;

interface LandCoverSource
{
    /**
     * Yields forest polygons, chunk by chunk, so large areas never need to be held
     * in memory at once.
     *
     * @return iterable<int, list<ForestPolygon>>
     */
    public function forestPolygons(BoundingBox $bounds): iterable;

    /**
     * @return iterable<int, list<WaterFeature>>
     */
    public function waterFeatures(BoundingBox $bounds): iterable;

    /**
     * Number of tiles that could not be retrieved, so callers can report partial
     * coverage instead of silently mapping holes.
     */
    public function unavailableChunks(): int;
}
