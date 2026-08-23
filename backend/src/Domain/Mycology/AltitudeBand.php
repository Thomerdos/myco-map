<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

final readonly class AltitudeBand
{
    public function __construct(
        public int $minimum,
        public int $optimumLow,
        public int $optimumHigh,
        public int $maximum,
    ) {
    }

    /**
     * Trapezoidal membership: full marks inside the optimum, tapering to zero at the
     * absolute limits.
     */
    public function suitability(int $elevation): float
    {
        if ($elevation <= $this->minimum || $elevation >= $this->maximum) {
            return 0.0;
        }
        if ($elevation >= $this->optimumLow && $elevation <= $this->optimumHigh) {
            return 1.0;
        }
        if ($elevation < $this->optimumLow) {
            return ($elevation - $this->minimum) / max(1, $this->optimumLow - $this->minimum);
        }

        return ($this->maximum - $elevation) / max(1, $this->maximum - $this->optimumHigh);
    }
}
