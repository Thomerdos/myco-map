<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

/**
 * Thresholds follow the potential legend. 90 is the hotspot cut for secteurs;
 * the colour ramp is yellow→red to 90, then frank violet/fuchsia (visual only).
 */
enum SuitabilityLevel: string
{
    /** Connected patches painted as "secteurs" start here. */
    public const HOTSPOT_THRESHOLD = 90.0;

    case Exceptional = 'exceptional';
    case Excellent = 'excellent';
    case Good = 'good';
    case Moderate = 'moderate';
    case Low = 'low';
    case Unsuitable = 'unsuitable';

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score >= 96 => self::Exceptional,
            $score >= self::HOTSPOT_THRESHOLD => self::Excellent,
            $score >= 78 => self::Good,
            $score >= 62 => self::Moderate,
            $score >= 40 => self::Low,
            default => self::Unsuitable,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Exceptional => 'Exceptionnel',
            self::Excellent => 'Prometteur',
            self::Good => 'Correct',
            self::Moderate => 'Moyen',
            self::Low => 'Faible',
            self::Unsuitable => 'À éviter',
        };
    }
}
