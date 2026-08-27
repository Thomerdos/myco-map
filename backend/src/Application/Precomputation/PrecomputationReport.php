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
        public int $geologyPolygons,
        public int $waterFeatures,
        public int $accessWays,
        public int $canopyCoverCells,
        public int $canopyHeightCells,
        public int $soilPhCells,
        public int $unavailableChunks,
        public float $durationSeconds,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->unavailableChunks === 0;
    }
}
