<?php

namespace App\Service;

use App\Model\GridCell;
use App\Model\Species;
use App\Model\TerrainData;

final class SpeciesScoringService
{
    public function __construct(
        private readonly WeatherService $weatherService,
    ) {
    }

    /**
     * @param array{elevation: float, slope: float, aspect: float, aspectLabel: string} $terrain
     * @param array{type: string, confidence: float} $forest
     * @param array<string, mixed> $weather
     */
    public function scoreCell(
        Species $species,
        array $terrain,
        array $forest,
        array $weather,
        float $lat,
        float $lng,
        string $id,
    ): GridCell {
        $reasons = [];
        $score = 0.0;
        $weights = 0.0;

        $inSeason = false;
        foreach ($species->harvestWindows as $window) {
            if ($this->weatherService->isInHarvestWindow($window['start'], $window['end'])) {
                $inSeason = true;
                if ($window['peak']) {
                    $reasons[] = 'Saison de cueillette actuelle (' . $window['label'] . ')';
                }
                break;
            }
        }

        if (!$inSeason) {
            $reasons[] = 'Hors fenêtre de cueillette pour cette espèce';
        }

        $seasonScore = $inSeason ? 85 : 25;
        $score += $seasonScore * 0.15;
        $weights += 0.15;

        $weatherScore = (float) ($weather['score'] ?? 50);
        $score += $weatherScore * 0.25;
        $weights += 0.25;
        foreach ($weather['reasons'] ?? [] as $reason) {
            $reasons[] = $reason;
        }

        $terrainScore = $this->scoreTerrainForSpecies($species->id, $terrain, $reasons);
        $score += $terrainScore * 0.35;
        $weights += 0.35;

        $forestScore = $this->scoreForestForSpecies($species->id, $forest, $reasons);
        $score += $forestScore * 0.25;
        $weights += 0.25;

        $finalScore = $weights > 0 ? ($score / $weights) : 0;
        if (!$inSeason) {
            $finalScore = min($finalScore, 45);
        }

        return new GridCell(
            id: $id,
            lat: $lat,
            lng: $lng,
            score: $finalScore,
            level: $this->levelFromScore($finalScore),
            reasons: array_values(array_unique($reasons)),
            terrain: new TerrainData(
                elevation: $terrain['elevation'],
                slope: $terrain['slope'],
                aspect: $terrain['aspect'],
                aspectLabel: $terrain['aspectLabel'],
                forestType: $forest['type'],
                forestConfidence: $forest['confidence'],
            ),
        );
    }

    /**
     * @param list<string> $reasons
     */
    private function scoreTerrainForSpecies(string $speciesId, array $terrain, array &$reasons): float
    {
        $elevation = $terrain['elevation'];
        $slope = $terrain['slope'];
        $aspect = $terrain['aspect'];
        $aspectLabel = $terrain['aspectLabel'];

        $score = 50.0;

        $altitudeRange = match ($speciesId) {
            'cepe' => [500, 1700],
            'trompette' => [400, 1500],
            'chanterelle' => [400, 2000],
            'girolle' => [500, 1800],
            'pied_mouton' => [500, 1700],
            'morille' => [400, 1400],
            default => [400, 1800],
        };

        if ($elevation >= $altitudeRange[0] && $elevation <= $altitudeRange[1]) {
            $score += 25;
            $reasons[] = sprintf('Altitude favorable (~%d m)', (int) $elevation);
        } elseif ($elevation < $altitudeRange[0]) {
            $score -= 10;
            $reasons[] = sprintf('Altitude basse pour cette espèce (%d m)', (int) $elevation);
        } else {
            $score -= 15;
            $reasons[] = sprintf('Altitude élevée (%d m), conditions plus sévères', (int) $elevation);
        }

        $preferredAspects = match ($speciesId) {
            'cepe', 'trompette', 'pied_mouton' => ['N', 'NE', 'NO'],
            'chanterelle', 'girolle' => ['N', 'NE', 'NO', 'E'],
            'morille' => ['S', 'SE', 'E'],
            default => ['N', 'NE'],
        };

        if (in_array($aspectLabel, $preferredAspects, true)) {
            $score += 20;
            $reasons[] = 'Exposition ' . $aspectLabel . ' favorable en montagne (versant frais/humide)';
        } elseif (in_array($aspectLabel, ['S', 'SO'], true) && $speciesId !== 'morille') {
            $score -= 12;
            $reasons[] = 'Exposition ' . $aspectLabel . ' plus sèche, moins propice';
        }

        if ($speciesId === 'morille' && in_array($aspectLabel, ['S', 'SE'], true)) {
            $score += 15;
            $reasons[] = 'Versant ensoleillé favorable au réchauffement printanier';
        }

        $slopeRange = match ($speciesId) {
            'trompette' => [0, 25],
            'morille' => [5, 35],
            default => [5, 30],
        };

        if ($slope >= $slopeRange[0] && $slope <= $slopeRange[1]) {
            $score += 10;
            $reasons[] = sprintf('Pente modérée (%.0f°)', $slope);
        } elseif ($slope > 35) {
            $score -= 10;
            $reasons[] = 'Pente forte, milieu moins stable';
        }

        return max(0, min(100, $score));
    }

    /**
     * @param list<string> $reasons
     */
    private function scoreForestForSpecies(string $speciesId, array $forest, array &$reasons): float
    {
        $type = $forest['type'];
        $confidence = $forest['confidence'];

        if ($type === 'non_forestier') {
            if ($speciesId === 'morille') {
                $reasons[] = 'Zone ouverte / lisière possible pour morilles';
                return 55;
            }
            $reasons[] = 'Zone non forestière';
            return 15;
        }

        $score = 60;
        $preferred = match ($speciesId) {
            'cepe' => ['feuillu', 'mixte', 'forestier'],
            'trompette' => ['feuillu', 'mixte', 'forestier'],
            'chanterelle', 'girolle' => ['mixte', 'conifere', 'feuillu', 'forestier'],
            'pied_mouton' => ['conifere', 'mixte', 'feuillu', 'forestier'],
            'morille' => ['feuillu', 'mixte', 'forestier'],
            default => ['forestier'],
        };

        if (in_array($type, $preferred, true)) {
            $score += 30;
            $reasons[] = 'Type de forêt compatible (' . $this->forestLabel($type) . ')';
        } else {
            $score -= 10;
            $reasons[] = 'Type de forêt peu adapté (' . $this->forestLabel($type) . ')';
        }

        if ($confidence >= 0.8) {
            $score += 5;
        }

        return max(0, min(100, $score));
    }

    private function forestLabel(string $type): string
    {
        return match ($type) {
            'feuillu' => 'feuillu',
            'conifere' => 'conifère',
            'mixte' => 'mixte',
            'forestier' => 'forestier',
            default => 'non forestier',
        };
    }

    private function levelFromScore(float $score): string
    {
        return match (true) {
            $score >= 75 => 'excellent',
            $score >= 60 => 'bon',
            $score >= 45 => 'moyen',
            default => 'faible',
        };
    }
}
