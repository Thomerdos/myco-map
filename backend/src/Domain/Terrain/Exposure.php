<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

/**
 * Slope orientation expressed both as a compass label and as a "coolness" index,
 * the property that actually drives fungal moisture retention in mountain terrain.
 */
final readonly class Exposure
{
    public const FLAT = -1.0;

    public function __construct(
        public float $aspectDegrees,
        public float $slopeDegrees,
    ) {
    }

    public function isFlat(): bool
    {
        return $this->aspectDegrees < 0 || $this->slopeDegrees < 2.0;
    }

    /**
     * 1.0 = coldest, moistest situation (north facing, steep).
     * 0.0 = hottest, driest situation (south facing, steep).
     * Flat ground sits in the middle because orientation stops mattering.
     */
    public function coolness(): float
    {
        if ($this->isFlat()) {
            return 0.5;
        }

        $northness = (cos(deg2rad($this->aspectDegrees)) + 1) / 2;
        $amplitude = min(1.0, $this->slopeDegrees / 30.0);

        return 0.5 + ($northness - 0.5) * $amplitude;
    }

    public function cardinal(): string
    {
        if ($this->isFlat()) {
            return 'plat';
        }

        $sectors = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];
        $index = (int) floor((fmod($this->aspectDegrees + 22.5, 360)) / 45);

        return $sectors[$index] ?? 'N';
    }
}
