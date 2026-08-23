<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

final readonly class SlopeBand
{
    public function __construct(
        public float $optimumLow,
        public float $optimumHigh,
        public float $maximum = 45.0,
    ) {
    }

    public function suitability(float $slopeDegrees): float
    {
        if ($slopeDegrees >= $this->maximum) {
            return 0.0;
        }
        if ($slopeDegrees >= $this->optimumLow && $slopeDegrees <= $this->optimumHigh) {
            return 1.0;
        }
        if ($slopeDegrees < $this->optimumLow) {
            return 0.6 + 0.4 * ($slopeDegrees / max(0.1, $this->optimumLow));
        }

        return max(0.0, ($this->maximum - $slopeDegrees) / max(0.1, $this->maximum - $this->optimumHigh));
    }
}
