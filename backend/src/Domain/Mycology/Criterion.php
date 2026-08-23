<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

enum Criterion: string
{
    case Season = 'season';
    case Weather = 'weather';
    case Altitude = 'altitude';
    case Exposure = 'exposure';
    case Cover = 'cover';
    case Moisture = 'moisture';
    case Edge = 'edge';
    case Slope = 'slope';

    public function label(): string
    {
        return match ($this) {
            self::Season => 'Saison',
            self::Weather => 'Météo récente',
            self::Altitude => 'Altitude',
            self::Exposure => 'Exposition',
            self::Cover => 'Couvert forestier',
            self::Moisture => 'Humidité topographique',
            self::Edge => 'Position lisière',
            self::Slope => 'Pente',
        };
    }

    public function weight(): float
    {
        return match ($this) {
            self::Season => 0.16,
            self::Weather => 0.22,
            self::Altitude => 0.15,
            self::Exposure => 0.15,
            self::Cover => 0.18,
            self::Moisture => 0.08,
            self::Edge => 0.04,
            self::Slope => 0.02,
        };
    }
}
