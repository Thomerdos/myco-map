<?php

declare(strict_types=1);

namespace App\Domain\Cartography;

use App\Domain\Terrain\ForestCover;
use App\Domain\Mycology\ScoringMode;

final readonly class LayerLegendFactory
{
    public function create(MapLayer $layer, ScoringMode $mode = ScoringMode::Moment): LayerLegend
    {
        return match ($layer) {
            // Two families so the eye can group at a glance: gold wash for the common
            // 70–85 forest plateau, then a hard jump to magenta (≥ 90) that does not
            // exist on the basemap. Green is avoided. The empty stop holds gold so
            // RGB interpolation never turns 80–89 into muddy mauve.
            MapLayer::Potential => $this->potentialLegend(
                $mode === ScoringMode::Habitat ? 'Potentiel d\'habitat' : $layer->label(),
            ),
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
            MapLayer::StandDensity => new LayerLegend($layer->label(), '%', false, [
                ['value' => 0, 'label' => '0 %', 'color' => '#e8e4d9'],
                ['value' => 25, 'label' => 'Ouvert', 'color' => '#9ccc65'],
                ['value' => 55, 'label' => 'Optimum', 'color' => '#f0c14a'],
                ['value' => 80, 'label' => 'Fermé', 'color' => '#2e7d4f'],
                ['value' => 100, 'label' => '100 %', 'color' => '#123524'],
            ]),
            MapLayer::Geology => new LayerLegend($layer->label(), 'pH', false, [
                ['value' => 4.5, 'label' => 'Acide', 'color' => '#8b5a3c'],
                ['value' => 5.5, 'label' => '5,5', 'color' => '#c4a35a'],
                ['value' => 6.5, 'label' => 'Neutre', 'color' => '#e8e4d9'],
                ['value' => 7.5, 'label' => 'Basique', 'color' => '#d4c48a'],
                ['value' => 8.2, 'label' => 'Calcaire', 'color' => '#f0e6b8'],
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
            // Continuous millimetres of the water the model uses (episode 65 % +
            // 26-day accumulation 35 %). Same warm→cool ramp as before so orographic
            // contrast stays readable on the forest basemap.
            MapLayer::Weather => new LayerLegend($layer->label(), 'mm', false, [
                ['value' => 0, 'label' => 'Sec', 'color' => '#f5c542'],
                ['value' => 15, 'label' => '15 mm', 'color' => '#ff6b3d'],
                ['value' => 30, 'label' => '30 mm', 'color' => '#d946ef'],
                ['value' => 45, 'label' => '45 mm', 'color' => '#2563eb'],
                ['value' => 70, 'label' => '70 mm et plus', 'color' => '#0b1f6b'],
            ]),
            // Low metres (near a trailhead) must pop; unreachable cells are left
            // unpainted by the renderer so this ramp only covers the walkable budget.
            MapLayer::Access => new LayerLegend($layer->label(), 'm', false, [
                ['value' => 0, 'label' => 'Au départ', 'color' => '#22d3ee'],
                ['value' => 500, 'label' => '500 m', 'color' => '#0ea5e9'],
                ['value' => 1000, 'label' => '1 km', 'color' => '#2563eb'],
                ['value' => 2000, 'label' => '2 km à pied', 'color' => '#1e3a8a'],
            ]),
        };
    }

    private function potentialLegend(string $title): LayerLegend
    {
        return new LayerLegend($title, null, false, [
            ['value' => 0, 'label' => 'À éviter', 'color' => '#14161e'],
            ['value' => 40, 'label' => 'Faible', 'color' => '#2a3142'],
            ['value' => 62, 'label' => 'Moyen', 'color' => '#4a5568'],
            ['value' => 78, 'label' => 'Correct', 'color' => '#ffc01a'],
            ['value' => 86, 'label' => '', 'color' => '#ffd24d'],
            ['value' => 90, 'label' => 'Prometteur', 'color' => '#c026d3'],
            ['value' => 96, 'label' => 'Exceptionnel', 'color' => '#ff4ef0'],
        ], true);
    }
}
