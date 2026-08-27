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
            // Deux familles : jaune→rouge 70–90, puis violet/fuchsia franc ≥ 90
            // (seuil excellence). Sous 70 reste fantôme.
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
            // Diverging acid→alkaline (RdYlBu-like): orange/red vs blue so Chartreuse
            // limestone and Belledonne granite separate on a forest basemap. Stops hug
            // the EcoDataCube range (~5–7.6) so the mid band is not a flat beige wash.
            MapLayer::Geology => new LayerLegend($layer->label(), 'pH', false, [
                ['value' => 5.0, 'label' => 'Acide', 'color' => '#b2182b'],
                ['value' => 5.8, 'label' => '5,8', 'color' => '#ef8a62'],
                ['value' => 6.5, 'label' => 'Neutre', 'color' => '#f7f7f7'],
                ['value' => 7.2, 'label' => 'Basique', 'color' => '#67a9cf'],
                ['value' => 7.8, 'label' => 'Calcaire', 'color' => '#2166ac'],
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
            ['value' => 0, 'label' => 'À éviter', 'color' => '#0c0e14'],
            ['value' => 60, 'label' => '', 'color' => '#1e2430'],
            ['value' => 70, 'label' => '70', 'color' => '#eab308'],
            ['value' => 75, 'label' => '', 'color' => '#f59e0b'],
            ['value' => 80, 'label' => '80', 'color' => '#f97316'],
            ['value' => 85, 'label' => 'Correct', 'color' => '#ea580c'],
            ['value' => 90, 'label' => '90', 'color' => '#dc2626'],
            ['value' => 93, 'label' => 'Prometteur', 'color' => '#c026d3'],
            ['value' => 96, 'label' => '96', 'color' => '#e879f9'],
            ['value' => 100, 'label' => 'Top', 'color' => '#fce7f3'],
        ], true);
    }
}
