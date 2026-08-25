<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

/**
 * Trapezoid affinity on soil pH (H₂O). Mycological optima are priors: calcicole
 * species peak near neutral–alkaline, acidophiles around 5–6.
 */
final readonly class PhBand
{
    public function __construct(
        public float $optimumLow = 5.5,
        public float $optimumHigh = 7.0,
        public float $floor = 0.22,
        public float $softLow = 4.0,
        public float $softHigh = 8.5,
    ) {
    }

    public function suitability(float $ph): float
    {
        if ($ph <= $this->softLow || $ph >= $this->softHigh) {
            return $this->floor;
        }

        if ($ph < $this->optimumLow) {
            $span = max(0.01, $this->optimumLow - $this->softLow);
            $t = ($ph - $this->softLow) / $span;

            return $this->floor + (1.0 - $this->floor) * $t;
        }

        if ($ph <= $this->optimumHigh) {
            return 1.0;
        }

        $span = max(0.01, $this->softHigh - $this->optimumHigh);
        $t = ($ph - $this->optimumHigh) / $span;

        return 1.0 + ($this->floor - 1.0) * $t;
    }
}
