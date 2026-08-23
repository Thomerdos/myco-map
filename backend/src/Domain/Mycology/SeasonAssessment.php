<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

/**
 * Season standing for a species at a given date. Computed once per request rather
 * than per cell, since it does not vary across the map.
 */
final readonly class SeasonAssessment
{
    public ?HarvestWindow $activeWindow;
    public ?HarvestWindow $nextWindow;
    public CriterionScore $criterionScore;

    public function __construct(
        public Species $species,
        public \DateTimeImmutable $date,
    ) {
        $this->activeWindow = $species->activeWindow($date);
        $this->nextWindow = $this->activeWindow === null ? $this->findNextWindow() : null;
        $this->criterionScore = $this->buildCriterionScore();
    }

    public function isInSeason(): bool
    {
        return $this->activeWindow !== null;
    }

    private function buildCriterionScore(): CriterionScore
    {
        if ($this->activeWindow === null) {
            return new CriterionScore(
                Criterion::Season,
                10.0,
                $this->nextWindow !== null
                    ? sprintf('Hors saison — prochaine fenêtre : %s', $this->nextWindow->label)
                    : 'Hors saison pour cette espèce',
            );
        }

        return new CriterionScore(
            Criterion::Season,
            $this->activeWindow->isPeak ? 100.0 : 70.0,
            $this->activeWindow->isPeak
                ? sprintf('En pleine saison : %s', $this->activeWindow->label)
                : sprintf('Saison secondaire : %s', $this->activeWindow->label),
        );
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
