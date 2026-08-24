<?php

declare(strict_types=1);

namespace App\Domain\Cartography;

use App\Domain\Terrain\TerrainProfile;
use App\Domain\Weather\WeatherConditions;

final readonly class LayerValueResolver
{
    public function resolve(
        MapLayer $layer,
        TerrainProfile $terrain,
        WeatherConditions $weather,
        float $potential,
        float $waterMillimetres = 0.0,
    ): float {
        return match ($layer) {
            MapLayer::Potential => $potential,
            MapLayer::Elevation => (float) $terrain->elevationMeters,
            MapLayer::Exposure => $terrain->exposure()->coolness() * 100,
            MapLayer::Slope => $terrain->slopeDegrees,
            // Cover layer stays on the ForestCover class (0–4). Host and canopy live in
            // the packed StandCode but must not shift this categorical colour scale.
            MapLayer::Cover => (float) $terrain->cover->value,
            MapLayer::StandDensity => (float) $terrain->canopy->value,
            MapLayer::Geology => (float) $terrain->substrate->value,
            MapLayer::Moisture => $terrain->moistureIndex() * 100,
            MapLayer::ForestEdge => (float) max(0, $terrain->edgeDistanceMeters),
            MapLayer::Weather => $waterMillimetres,
            MapLayer::Access => (float) $terrain->accessDistanceMeters,
        };
    }
}
