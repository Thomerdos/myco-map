<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

/**
 * Moment: weather + habitat, with season as a cap (not a weight). Habitat: terrain
 * only, remaining weights renormalised so they still sum to 1.0.
 */
enum ScoringMode: string
{
    case Moment = 'moment';
    case Habitat = 'habitat';

    /** Criteria that participate in this mode. */
    public function criteria(): array
    {
        return match ($this) {
            self::Moment => [
                Criterion::Weather,
                Criterion::Altitude,
                Criterion::Exposure,
                Criterion::Cover,
                Criterion::StandDensity,
                Criterion::Geology,
                Criterion::Moisture,
                Criterion::Edge,
                Criterion::Slope,
            ],
            self::Habitat => [
                Criterion::Altitude,
                Criterion::Exposure,
                Criterion::Cover,
                Criterion::StandDensity,
                Criterion::Geology,
                Criterion::Moisture,
                Criterion::Edge,
                Criterion::Slope,
            ],
        };
    }

    /**
     * Weight of a criterion after dropping inactive ones and renormalising.
     * Inactive criteria return 0.
     */
    public function weight(Criterion $criterion): float
    {
        $active = $this->criteria();
        if (!\in_array($criterion, $active, true)) {
            return 0.0;
        }

        if ($this === self::Moment) {
            return $criterion->weight();
        }

        $sum = 0.0;
        foreach ($active as $item) {
            $sum += $item->weight();
        }

        return $sum > 0.0 ? $criterion->weight() / $sum : 0.0;
    }

    public function includesMoment(): bool
    {
        return $this === self::Moment;
    }
}
