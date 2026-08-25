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
    ): ?float {
        return match ($layer) {
            MapLayer::Potential => $potential,
            MapLayer::Elevation => (float) $terrain->elevationMeters,
            MapLayer::Exposure => $terrain->exposure()->coolness() * 100,
            MapLayer::Slope => $terrain->slopeDegrees,
            // Cover layer stays on the ForestCover class (0–4). Host and canopy live in
            // the packed StandCode but must not shift this categorical colour scale.
            MapLayer::Cover => (float) $terrain->cover->value,
            MapLayer::StandDensity => $this->standDensityPercent($terrain),
            MapLayer::Geology => (float) $terrain->substrate->value,
            MapLayer::Moisture => $terrain->moistureIndex() * 100,
            MapLayer::ForestEdge => (float) max(0, $terrain->edgeDistanceMeters),
            MapLayer::Weather => $waterMillimetres,
            MapLayer::Access => (float) $terrain->accessDistanceMeters,
        };
    }

    /**
     * Continuous TCD percent when known; FO ≈ 25 % / FF ≈ 70 % otherwise. Unknown
     * cells stay null so the renderer skips them.
     */
    private function standDensityPercent(TerrainProfile $terrain): ?float
    {
        if ($terrain->canopyCoverPercent !== null) {
            return (float) $terrain->canopyCoverPercent;
        }

        $proxy = $terrain->canopy->proxyCoverPercent();

        return $proxy === null ? null : (float) $proxy;
    }
}
