<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

enum SuitabilityLevel: string
{
    case Excellent = 'excellent';
    case Good = 'good';
    case Moderate = 'moderate';
    case Low = 'low';
    case Unsuitable = 'unsuitable';

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score >= 78 => self::Excellent,
            $score >= 62 => self::Good,
            $score >= 45 => self::Moderate,
            $score >= 25 => self::Low,
            default => self::Unsuitable,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Excellent => 'Très prometteur',
            self::Good => 'Prometteur',
            self::Moderate => 'Moyen',
            self::Low => 'Faible',
            self::Unsuitable => 'À éviter',
        };
    }
}
