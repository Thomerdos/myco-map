<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

/**
 * Bell curve on canopy height (metres), from LIDAR HD CHM when available.
 * Mature temperate stands that fruit well are seldom the tallest closed poles.
 */
final readonly class CanopyHeightBand
{
    public function __construct(
        public float $optimumLow = 14.0,
        public float $optimumHigh = 24.0,
        public float $closedFloor = 0.62,
        public float $sparseFloor = 0.22,
        public float $sparseUntil = 6.0,
        public float $tallAt = 40.0,
    ) {
    }

    public function suitability(float $meters): float
    {
        $meters = max(0.0, $meters);

        if ($meters <= $this->sparseUntil) {
            $t = $meters / max(0.1, $this->sparseUntil);

            return $this->sparseFloor + (1.0 - $this->sparseFloor) * $t * 0.35;
        }

        if ($meters < $this->optimumLow) {
            $t = ($meters - $this->sparseUntil) / max(0.1, $this->optimumLow - $this->sparseUntil);

            return $this->sparseFloor + (1.0 - $this->sparseFloor) * (0.35 + 0.65 * $t);
        }

        if ($meters <= $this->optimumHigh) {
            return 1.0;
        }

        $t = min(1.0, ($meters - $this->optimumHigh) / max(0.1, $this->tallAt - $this->optimumHigh));

        return 1.0 + ($this->closedFloor - 1.0) * $t;
    }
}
