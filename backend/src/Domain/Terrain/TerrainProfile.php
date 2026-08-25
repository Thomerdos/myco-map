<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

use App\Domain\Geo\Coordinates;
use App\Domain\Terrain\AccessThreshold;

/**
 * Everything the precomputation knows about one grid cell, independent of species
 * and weather.
 */
final readonly class TerrainProfile
{
    public function __construct(
        public Coordinates $coordinates,
        public int $elevationMeters,
        public float $slopeDegrees,
        public float $aspectDegrees,
        public float $curvature,
        public ForestCover $cover,
        public int $edgeDistanceMeters,
        public int $waterDistanceMeters,
        public HostTree $hostTree = HostTree::Unknown,
        public CanopyClosure $canopy = CanopyClosure::Unknown,
        public Substrate $substrate = Substrate::Unknown,
        public int $accessDistanceMeters = AccessThreshold::UNREACHABLE,
        public ?int $canopyCoverPercent = null,
        /** Soil pH in water (EcoDataCube); null when the raster is missing. */
        public ?float $soilPh = null,
    ) {
    }

    public function exposure(): Exposure
    {
        return new Exposure($this->aspectDegrees, $this->slopeDegrees);
    }

    /**
     * Positive curvature marks concave ground (combes, thalwegs) where moisture and
     * leaf litter accumulate; combined with gentle slopes and nearby streams it is a
     * good proxy for a topographic wetness index.
     */
    public function moistureIndex(): float
    {
        $concavity = max(0.0, min(1.0, ($this->curvature + 1.5) / 3.0));
        $drainage = 1.0 - min(1.0, $this->slopeDegrees / 45.0);
        $waterProximity = $this->waterDistanceMeters >= 1500
            ? 0.0
            : 1.0 - ($this->waterDistanceMeters / 1500);

        return max(0.0, min(1.0, 0.45 * $concavity + 0.35 * $drainage + 0.20 * $waterProximity));
    }

    public function isNearForestEdge(): bool
    {
        return abs($this->edgeDistanceMeters) <= 150;
    }

    /**
     * Packed stand integer stored in the single SQLite `cover` column. Old archives that
     * wrote only ForestCover 0–4 still round-trip: host and canopy sit in the high bits
     * and therefore unpack as {@see HostTree::Unknown} / {@see CanopyClosure::Unknown}.
     */
    public function standCode(): int
    {
        return StandCode::pack($this->cover, $this->hostTree, $this->canopy);
    }
}
