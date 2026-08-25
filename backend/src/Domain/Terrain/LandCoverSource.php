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
     * Roads, parkings, tracks and paths used to decide whether a stand is reachable
     * on foot from a place you can leave a car.
     *
     * When $clip is set, chunks that do not intersect it are skipped, but the
     * remaining cache keys stay those of a full $bounds tiling (precompute).
     *
     * @return iterable<int, list<AccessWay>>
     */
    public function accessWays(BoundingBox $bounds, ?BoundingBox $clip = null): iterable;

    /**
     * Number of tiles that could not be retrieved, so callers can report partial
     * coverage instead of silently mapping holes.
     */
    public function unavailableChunks(): int;
}
