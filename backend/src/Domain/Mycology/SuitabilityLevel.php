<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

/**
 * Thresholds are calibrated on what the model actually produces rather than on an even
 * split of 0–100. In season and after rain a forested cell rarely drops below 60, so the
 * old cut at 62 labelled nine forested cells out of ten "prometteur" and the word stopped
 * meaning anything. They stay aligned with the stops of the potential legend.
 */
enum SuitabilityLevel: string
{
    case Exceptional = 'exceptional';
    case Excellent = 'excellent';
    case Good = 'good';
    case Moderate = 'moderate';
    case Low = 'low';
    case Unsuitable = 'unsuitable';

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score >= 94 => self::Exceptional,
            $score >= 87 => self::Excellent,
            $score >= 78 => self::Good,
            $score >= 64 => self::Moderate,
            $score >= 45 => self::Low,
            default => self::Unsuitable,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Exceptional => 'Exceptionnel',
            self::Excellent => 'Très prometteur',
            self::Good => 'Prometteur',
            self::Moderate => 'Moyen',
            self::Low => 'Faible',
            self::Unsuitable => 'À éviter',
        };
    }
}
