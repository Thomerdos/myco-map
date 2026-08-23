<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

/**
 * Slope suitability decreases monotonically: yield studies in mountain pine stands find
 * increasing slope reduces mushroom production, with no optimum at intermediate angles
 * (Bonet et al. 2010). Thinner soils and faster runoff are the usual explanation, so the
 * curve declines gently across the tolerated range and steeply beyond it. Flat ground is
 * deliberately *not* penalised — see AGENTS.md.
 */
final readonly class SlopeBand
{
    public function __construct(
        public float $toleratedUpTo,
        public float $maximum = 45.0,
    ) {
    }

    public function suitability(float $slopeDegrees): float
    {
        $slope = max(0.0, $slopeDegrees);

        if ($slope >= $this->maximum) {
            return 0.0;
        }

        if ($slope <= $this->toleratedUpTo) {
            return 1.0 - 0.15 * ($slope / max(0.1, $this->toleratedUpTo));
        }

        return 0.85 * ($this->maximum - $slope) / max(0.1, $this->maximum - $this->toleratedUpTo);
    }
}
