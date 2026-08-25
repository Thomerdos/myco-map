<?php

declare(strict_types=1);

namespace App\Domain\Cartography;

/**
 * The masks that can be displayed on the map: the combined "where to look" score,
 * or any single criterion feeding into it.
 */
enum MapLayer: string
{
    case Potential = 'potential';
    case Elevation = 'elevation';
    case Exposure = 'exposure';
    case Slope = 'slope';
    case Cover = 'cover';
    case StandDensity = 'stand_density';
    case Geology = 'geology';
    case Moisture = 'moisture';
    case ForestEdge = 'edge';
    case Weather = 'weather';
    case Access = 'access';

    public function label(): string
    {
        return match ($this) {
            self::Potential => 'Indice de pousse',
            self::Elevation => 'Altitude',
            self::Exposure => 'Exposition (fraîcheur du versant)',
            self::Slope => 'Pente',
            self::Cover => 'Couvert forestier',
            self::StandDensity => 'Densité du peuplement',
            self::Geology => 'Géologie / substrat',
            self::Moisture => 'Humidité topographique',
            self::ForestEdge => 'Distance à la lisière',
            self::Weather => 'Apport en eau',
            self::Access => 'Accès (parking + chemin)',
        };
    }

    /**
     * Accepts the former rainfall id so old clients keep working.
     */
    public static function fromQuery(string $value): self
    {
        if ($value === 'rainfall') {
            return self::Weather;
        }

        return self::from($value);
    }

    public function requiresSpecies(): bool
    {
        return $this === self::Potential;
    }

    public function isCategorical(): bool
    {
        return match ($this) {
            self::Cover, self::Geology => true,
            default => false,
        };
    }

    public function unit(): ?string
    {
        return match ($this) {
            self::Elevation, self::ForestEdge, self::Access => 'm',
            self::Slope => '°',
            self::StandDensity => '%',
            self::Weather => 'mm',
            default => null,
        };
    }
}
