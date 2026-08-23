<?php

declare(strict_types=1);

namespace App\Domain\Cartography;

use App\Domain\Terrain\ForestCover;

final readonly class LayerLegendFactory
{
    public function create(MapLayer $layer): LayerLegend
    {
        return match ($layer) {
            MapLayer::Potential => new LayerLegend($layer->label(), null, false, [
                ['value' => 0, 'label' => 'À éviter', 'color' => '#3b3f4a'],
                ['value' => 25, 'label' => 'Faible', 'color' => '#8d6e4a'],
                ['value' => 45, 'label' => 'Moyen', 'color' => '#d8b845'],
                ['value' => 62, 'label' => 'Prometteur', 'color' => '#79b03a'],
                ['value' => 78, 'label' => 'Très prometteur', 'color' => '#1c7a3e'],
            ]),
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
            MapLayer::Rainfall => new LayerLegend($layer->label(), 'mm', false, [
                ['value' => 0, 'label' => 'Sec', 'color' => '#e8d9b5'],
                ['value' => 20, 'label' => '20 mm', 'color' => '#7fb7c9'],
                ['value' => 45, 'label' => '45 mm et plus', 'color' => '#1d4e8a'],
            ]),
        };
    }
}
