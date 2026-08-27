<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

/**
 * Bell curve on tree-cover percent. Yield models peak at intermediate basal area
 * (~15–20 m²/ha), not at the most closed canopy, so 90 %+ cover scores below the
 * optimum — that is what splits previously identical FF cells.
 */
final readonly class CanopyDensityBand
{
    public function __construct(
        public int $optimumLow = 55,
        public int $optimumHigh = 65,
        public float $closedFloor = 0.66,
        public float $sparseFloor = 0.20,
    ) {
    }

    public function suitability(int $percent): float
    {
        $percent = max(0, min(100, $percent));

        if ($percent <= 8) {
            return $this->sparseFloor;
        }

        if ($percent < $this->optimumLow) {
            $t = ($percent - 8) / max(1, $this->optimumLow - 8);

            return $this->sparseFloor + (1.0 - $this->sparseFloor) * $t;
        }

        if ($percent <= $this->optimumHigh) {
            return 1.0;
        }

        $t = ($percent - $this->optimumHigh) / max(1, 100 - $this->optimumHigh);

        return 1.0 + ($this->closedFloor - 1.0) * $t;
    }
}
