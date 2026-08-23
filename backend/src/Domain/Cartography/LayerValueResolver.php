<?php

declare(strict_types=1);

namespace App\Domain\Cartography;

use App\Domain\Mycology\SuitabilityScore;
use App\Domain\Terrain\TerrainProfile;
use App\Domain\Weather\WeatherConditions;

final readonly class LayerValueResolver
{
    public function resolve(
        MapLayer $layer,
        TerrainProfile $terrain,
        WeatherConditions $weather,
        ?SuitabilityScore $score,
    ): ?float {
        return match ($layer) {
            MapLayer::Potential => $score?->value,
            MapLayer::Elevation => (float) $terrain->elevationMeters,
            MapLayer::Exposure => $terrain->exposure()->coolness() * 100,
            MapLayer::Slope => $terrain->slopeDegrees,
            MapLayer::Cover => (float) $terrain->cover->value,
            MapLayer::Moisture => $terrain->moistureIndex() * 100,
            MapLayer::ForestEdge => (float) max(0, $terrain->edgeDistanceMeters),
            MapLayer::Rainfall => $weather->triggerRainMillimetres,
        };
    }
}
