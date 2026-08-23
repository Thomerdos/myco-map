<?php

declare(strict_types=1);

namespace App\Application\Precomputation;

final readonly class PrecomputationReport
{
    public function __construct(
        public int $cells,
        public int $columns,
        public int $rows,
        public int $cellSizeMeters,
        public int $elevationTiles,
        public int $forestPolygons,
        public int $waterFeatures,
        public float $durationSeconds,
    ) {
    }
}
