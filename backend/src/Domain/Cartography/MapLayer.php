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
    case Rainfall = 'rainfall';

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
            self::Rainfall => 'Pluie déclenchante',
        };
    }

    public function requiresSpecies(): bool
    {
        return $this === self::Potential;
    }

    public function isCategorical(): bool
    {
        return match ($this) {
            self::Cover, self::StandDensity, self::Geology => true,
            default => false,
        };
    }

    public function unit(): ?string
    {
        return match ($this) {
            self::Elevation, self::ForestEdge => 'm',
            self::Slope => '°',
            self::Rainfall => 'mm',
            default => null,
        };
    }
}
