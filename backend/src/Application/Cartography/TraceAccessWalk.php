<?php

declare(strict_types=1);

namespace App\Application\Cartography;

use App\Domain\Geo\BoundingBox;
use App\Domain\Geo\Coordinates;
use App\Domain\Geo\Grid;
use App\Domain\Terrain\AccessThreshold;
use App\Domain\Terrain\AccessWalk;
use App\Domain\Terrain\TerrainCellStore;
use App\Domain\Terrain\TerrainProfile;
use App\Infrastructure\Raster\PathNetworkAccess;

/**
 * Rebuilds the parking-to-cell walk on click. Precompute stores the park/path
 * mask and a scalar distance; the polyline is reconstructed from that mask.
 */
final readonly class TraceAccessWalk
{
    private const WINDOW_MARGIN_METERS = 300;
    private const METERS_PER_DEGREE_LATITUDE = 111_320.0;
    private const MAX_WINDOW_CELLS = 250_000;

    public function __construct(
        private TerrainCellStore $cellStore,
        private PathNetworkAccess $pathAccess,
    ) {
    }

    public function for(TerrainProfile $profile): AccessWalk
    {
        if (!AccessThreshold::isAccessible($profile->accessDistanceMeters)) {
            return AccessWalk::unreachable();
        }

        $grid = $this->cellStore->storedGrid();
        if ($grid === null) {
            return AccessWalk::fromMeters($profile->accessDistanceMeters);
        }

        $traced = $this->traceOnWindow($grid, $profile);

        return $traced ?? AccessWalk::fromMeters($profile->accessDistanceMeters);
    }

    private function traceOnWindow(Grid $grid, TerrainProfile $profile): ?AccessWalk
    {
        $column = $grid->columnFor($profile->coordinates->longitude);
        $row = $grid->rowFor($profile->coordinates->latitude);
        if ($column < 0 || $row < 0 || $column >= $grid->columns || $row >= $grid->rows) {
            return null;
        }

        $window = $grid->windowFor($this->walkBounds($profile->coordinates), self::MAX_WINDOW_CELLS);
        if ($window === null || $window->step !== 1) {
            return null;
        }
        if ($column < $window->firstColumn || $column > $window->lastColumn
            || $row < $window->firstRow || $row > $window->lastRow) {
            return null;
        }

        $local = $grid->coveringWindow($window);
        $total = $local->cellCount();
        /** @var \SplFixedArray<int> $park */
        $park = new \SplFixedArray($total);
        /** @var \SplFixedArray<int> $path */
        $path = new \SplFixedArray($total);
        /** @var \SplFixedArray<float> $slope */
        $slope = new \SplFixedArray($total);
        for ($index = 0; $index < $total; $index++) {
            $park[$index] = 0;
            $path[$index] = 0;
            $slope[$index] = 0.0;
        }

        foreach ($this->cellStore->readWindow($window) as $cell) {
            $offset = $local->offset(
                $window->localColumn($cell['column']),
                $window->localRow($cell['row']),
            );
            $slope[$offset] = $cell['profile']->slopeDegrees;
            $park[$offset] = $cell['park'] ?? 0;
            $path[$offset] = $cell['path'] ?? 0;
        }

        $destination = $local->offset(
            $window->localColumn($column),
            $window->localRow($row),
        );

        return $this->pathAccess->trace($park, $path, $slope, $local, $destination);
    }

    private function walkBounds(Coordinates $point): BoundingBox
    {
        $meters = AccessThreshold::ALONG_PATH_METERS
            + AccessThreshold::APPROACH_METERS
            + self::WINDOW_MARGIN_METERS;
        $dLat = $meters / self::METERS_PER_DEGREE_LATITUDE;
        $cos = cos(deg2rad($point->latitude));
        $dLng = $cos > 0.1 ? $meters / (self::METERS_PER_DEGREE_LATITUDE * $cos) : $dLat;

        return new BoundingBox(
            $point->latitude - $dLat,
            $point->longitude - $dLng,
            $point->latitude + $dLat,
            $point->longitude + $dLng,
        );
    }
}
