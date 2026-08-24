<?php

declare(strict_types=1);

namespace App\Domain\Cartography;

use App\Domain\Terrain\CanopyClosure;
use App\Domain\Terrain\ForestCover;
use App\Domain\Terrain\Substrate;
use App\Domain\Mycology\ScoringMode;

final readonly class LayerLegendFactory
{
    public function create(MapLayer $layer, ScoringMode $mode = ScoringMode::Moment): LayerLegend
    {
        return match ($layer) {
            // Weather and season are near-uniform across a viewport, so in a good spell
            // most forest sits in the 70s–80s. Warm colours start at 90, the first band
            // that is actually rare. Green is avoided: the basemap is already forest.
            MapLayer::Potential => $mode === ScoringMode::Habitat
                ? new LayerLegend('Potentiel d\'habitat', null, false, [
                    ['value' => 0, 'label' => 'À éviter', 'color' => '#242733'],
                    ['value' => 40, 'label' => 'Faible', 'color' => '#2f3348'],
                    ['value' => 62, 'label' => 'Moyen', 'color' => '#3f4a6b'],
                    ['value' => 78, 'label' => 'Correct', 'color' => '#6f4a78'],
                    ['value' => 90, 'label' => 'Prometteur', 'color' => '#ef8b3c'],
                    ['value' => 96, 'label' => 'Exceptionnel', 'color' => '#ffe473'],
                ], true)
                : new LayerLegend($layer->label(), null, false, [
                    ['value' => 0, 'label' => 'À éviter', 'color' => '#242733'],
                    ['value' => 40, 'label' => 'Faible', 'color' => '#2f3348'],
                    ['value' => 62, 'label' => 'Moyen', 'color' => '#3f4a6b'],
                    ['value' => 78, 'label' => 'Correct', 'color' => '#6f4a78'],
                    ['value' => 90, 'label' => 'Prometteur', 'color' => '#ef8b3c'],
                    ['value' => 96, 'label' => 'Exceptionnel', 'color' => '#ffe473'],
                ], true),
            MapLayer::Elevation => new LayerLegend($layer->label(), 'm', false, [
                ['value' => 200, 'label' => '200 m', 'color' => '#1a6b3c'],
                ['value' => 700, 'label' => '700 m', 'color' => '#8fbf4a'],
                ['value' => 1200, 'label' => '1200 m', 'color' => '#e8d07a'],
                ['value' => 1700, 'label' => '1700 m', 'color' => '#b98a5a'],
                ['value' => 2200, 'label' => '2200 m', 'color' => '#f2f2f5'],
            ]),
            MapLayer::Exposure => new LayerLegend($layer->label(), null, false, [
                ['value' => 0, 'label' => 'Sud, chaud et sec', 'color' => '#c95b3a'],
                ['value' => 50, 'label' => 'Est / Ouest', 'color' => '#e6d089'],
                ['value' => 100, 'label' => 'Nord, frais et humide', 'color' => '#2f6fa8'],
            ]),
            MapLayer::Slope => new LayerLegend($layer->label(), '°', false, [
                ['value' => 0, 'label' => 'Plat', 'color' => '#f2efe6'],
                ['value' => 15, 'label' => '15°', 'color' => '#9fc06a'],
                ['value' => 30, 'label' => '30°', 'color' => '#d9932f'],
                ['value' => 45, 'label' => '45° et plus', 'color' => '#a3352a'],
            ]),
            MapLayer::Cover => new LayerLegend($layer->label(), null, true, [
                ['value' => ForestCover::Open->value, 'label' => ForestCover::Open->label(), 'color' => '#e8e4d9'],
                ['value' => ForestCover::Undetermined->value, 'label' => ForestCover::Undetermined->label(), 'color' => '#8fa87b'],
                ['value' => ForestCover::Broadleaf->value, 'label' => ForestCover::Broadleaf->label(), 'color' => '#7cb342'],
                ['value' => ForestCover::Conifer->value, 'label' => ForestCover::Conifer->label(), 'color' => '#1f6b4a'],
                ['value' => ForestCover::Mixed->value, 'label' => ForestCover::Mixed->label(), 'color' => '#4b9a6a'],
            ]),
            MapLayer::StandDensity => new LayerLegend($layer->label(), null, true, [
                ['value' => CanopyClosure::Unknown->value, 'label' => CanopyClosure::Unknown->shortLabel(), 'color' => '#c8c2b4'],
                ['value' => CanopyClosure::Open->value, 'label' => CanopyClosure::Open->label(), 'color' => '#7cb342'],
                ['value' => CanopyClosure::Closed->value, 'label' => CanopyClosure::Closed->label(), 'color' => '#1f4d2e'],
            ]),
            MapLayer::Geology => new LayerLegend($layer->label(), null, true, [
                ['value' => Substrate::Unknown->value, 'label' => Substrate::Unknown->label(), 'color' => '#c8c2b4'],
                ['value' => Substrate::Calcareous->value, 'label' => Substrate::Calcareous->label(), 'color' => '#d4c48a'],
                ['value' => Substrate::Siliceous->value, 'label' => Substrate::Siliceous->label(), 'color' => '#8b5a3c'],
                ['value' => Substrate::Mixed->value, 'label' => Substrate::Mixed->label(), 'color' => '#6a7a8a'],
            ]),
            MapLayer::Moisture => new LayerLegend($layer->label(), null, false, [
                ['value' => 0, 'label' => 'Drainant', 'color' => '#d8c9a3'],
                ['value' => 50, 'label' => 'Intermédiaire', 'color' => '#7fb28a'],
                ['value' => 100, 'label' => 'Humide', 'color' => '#1d5f8a'],
            ]),
            MapLayer::ForestEdge => new LayerLegend($layer->label(), 'm', false, [
                ['value' => 0, 'label' => 'Lisière', 'color' => '#e0a53f'],
                ['value' => 300, 'label' => '300 m', 'color' => '#6aa84f'],
                ['value' => 800, 'label' => 'Cœur de massif', 'color' => '#1d5c33'],
            ]),
            // Soft beige→cyan disappears on the green OSM forest. Warm dry tones and
            // saturated cool wet tones keep orographic contrast readable at typical
            // trigger totals (often ~10–35 mm across a massif viewport).
            MapLayer::Rainfall => new LayerLegend($layer->label(), 'mm', false, [
                ['value' => 0, 'label' => 'Sec', 'color' => '#f5c542'],
                ['value' => 10, 'label' => '10 mm', 'color' => '#ff6b3d'],
                ['value' => 20, 'label' => '20 mm', 'color' => '#d946ef'],
                ['value' => 30, 'label' => '30 mm', 'color' => '#2563eb'],
                ['value' => 45, 'label' => '45 mm et plus', 'color' => '#0b1f6b'],
            ]),
        };
    }
}
