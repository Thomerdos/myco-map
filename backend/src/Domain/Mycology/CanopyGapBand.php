<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

/**
 * Bell curve on canopy gap fraction (% of CHM pixels below ~3 m) inside a 50 m cell.
 * Moderate gaps (clairières / futaie irrégulière) favour fruiting; closed continuous
 * canopy and open clearcuts score lower.
 */
final readonly class CanopyGapBand
{
    public function __construct(
        public float $optimumLow = 8.0,
        public float $optimumHigh = 28.0,
        public float $closedFloor = 0.72,
        public float $openFloor = 0.45,
        public float $openAt = 55.0,
    ) {
    }

    public function suitability(float $gapPercent): float
    {
        $gapPercent = max(0.0, min(100.0, $gapPercent));

        if ($gapPercent < $this->optimumLow) {
            $t = $gapPercent / max(0.1, $this->optimumLow);

            return $this->closedFloor + (1.0 - $this->closedFloor) * $t;
        }

        if ($gapPercent <= $this->optimumHigh) {
            return 1.0;
        }

        $t = min(1.0, ($gapPercent - $this->optimumHigh) / max(0.1, $this->openAt - $this->optimumHigh));

        return 1.0 + ($this->openFloor - 1.0) * $t;
    }
}
