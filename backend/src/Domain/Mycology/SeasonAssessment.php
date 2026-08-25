<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

/**
 * Season standing for a species at a given date. Computed once per request rather
 * than per cell, since it does not vary across the map. It is a gate (cap + badge),
 * not a weighted criterion.
 */
final readonly class SeasonAssessment
{
    /** Moment-mode ceiling when the date is outside every harvest window. */
    public const OUT_OF_SEASON_CAP = 38.0;

    public ?HarvestWindow $activeWindow;
    public ?HarvestWindow $nextWindow;

    public function __construct(
        public Species $species,
        public \DateTimeImmutable $date,
    ) {
        $this->activeWindow = $species->activeWindow($date);
        $this->nextWindow = $this->activeWindow === null ? $this->findNextWindow() : null;
    }

    public function isInSeason(): bool
    {
        return $this->activeWindow !== null;
    }

    /**
     * Status line for the UI: in season vs capped out of season. French, user-facing.
     */
    public function gateMessage(): string
    {
        $cap = (int) self::OUT_OF_SEASON_CAP;

        if ($this->activeWindow === null) {
            $base = sprintf('Hors saison — score plafonné à %d', $cap);

            return $this->nextWindow !== null
                ? sprintf('%s. Prochaine fenêtre : %s', $base, $this->nextWindow->label)
                : $base;
        }

        return $this->activeWindow->isPeak
            ? sprintf('En saison : %s', $this->activeWindow->label)
            : sprintf('Saison secondaire : %s', $this->activeWindow->label);
    }

    private function findNextWindow(): ?HarvestWindow
    {
        for ($dayOffset = 1; $dayOffset <= 366; $dayOffset++) {
            $candidate = $this->date->modify(sprintf('+%d days', $dayOffset));
            $window = $this->species->activeWindow($candidate);
            if ($window !== null) {
                return $window;
            }
        }

        return null;
    }
}
