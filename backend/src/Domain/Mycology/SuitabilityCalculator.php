<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

use App\Domain\Terrain\TerrainProfile;
use App\Domain\Weather\WeatherConditions;

/**
 * Transparent, rule-based habitat model. Every criterion returns a 0..100 score with
 * a sentence explaining it, so a recommendation can always be justified on the map.
 */
final readonly class SuitabilityCalculator
{
    public function score(
        Species $species,
        TerrainProfile $terrain,
        WeatherConditions $weather,
        SeasonAssessment $season,
    ): SuitabilityScore {
        $inSeason = $season->isInSeason();

        $breakdown = [
            $season->criterionScore,
            $this->weather($weather),
            $this->altitude($species, $terrain),
            $this->exposure($species, $terrain),
            $this->cover($species, $terrain),
            $this->moisture($species, $terrain),
            $this->edge($species, $terrain),
            $this->slope($species, $terrain),
        ];

        $total = 0.0;
        foreach ($breakdown as $criterionScore) {
            $total += $criterionScore->weighted();
        }

        if ($species->requiresForest && !$terrain->cover->isForest()) {
            $total = min($total, 18.0);
        }
        if (!$inSeason) {
            $total = min($total, 38.0);
        }

        $total = max(0.0, min(100.0, $total));

        return new SuitabilityScore(
            $total,
            SuitabilityLevel::fromScore($total),
            $breakdown,
            $inSeason,
        );
    }

    private function weather(WeatherConditions $weather): CriterionScore
    {
        $trigger = $weather->triggerRainMillimetres;
        $triggerScore = match (true) {
            $trigger >= 45 => 100.0,
            $trigger >= 30 => 88.0,
            $trigger >= 20 => 72.0,
            $trigger >= 12 => 55.0,
            $trigger >= 6 => 35.0,
            default => 12.0,
        };

        $recent = $weather->recentRainMillimetres;
        $recentScore = match (true) {
            $recent >= 25 => 70.0,
            $recent >= 4 => 100.0,
            $recent >= 1 => 80.0,
            default => 45.0,
        };

        $temperature = $weather->meanTemperatureCelsius;
        $temperatureScore = match (true) {
            $temperature >= 9 && $temperature <= 17 => 100.0,
            $temperature >= 6 && $temperature < 9 => 78.0,
            $temperature > 17 && $temperature <= 21 => 70.0,
            $temperature > 21 => 35.0,
            $temperature >= 2 => 45.0,
            default => 12.0,
        };

        $humidityScore = min(100.0, max(20.0, ($weather->relativeHumidityPercent - 45) * 2.2));

        $value = $triggerScore * 0.46 + $recentScore * 0.2 + $temperatureScore * 0.22 + $humidityScore * 0.12;

        return new CriterionScore(
            Criterion::Weather,
            $value,
            sprintf(
                '%.0f mm de pluie déclenchante (J-14 à J-5), %.0f mm ces 5 derniers jours, %.1f °C de moyenne',
                $trigger,
                $recent,
                $temperature,
            ),
        );
    }

    private function altitude(Species $species, TerrainProfile $terrain): CriterionScore
    {
        $membership = $species->altitude->suitability($terrain->elevationMeters);
        $band = $species->altitude;

        $explanation = match (true) {
            $membership >= 0.95 => sprintf(
                '%d m, dans la tranche optimale %d–%d m',
                $terrain->elevationMeters,
                $band->optimumLow,
                $band->optimumHigh,
            ),
            $membership <= 0.01 => sprintf(
                '%d m, hors de l\'aire de l\'espèce (%d–%d m)',
                $terrain->elevationMeters,
                $band->minimum,
                $band->maximum,
            ),
            default => sprintf(
                '%d m, en marge de la tranche optimale %d–%d m',
                $terrain->elevationMeters,
                $band->optimumLow,
                $band->optimumHigh,
            ),
        };

        return new CriterionScore(Criterion::Altitude, $membership * 100, $explanation);
    }

    /**
     * In mountains the preferred orientation shifts with altitude: cool north-facing
     * slopes stay productive low down, while higher up the warmer southern slopes
     * catch up.
     */
    private function exposure(Species $species, TerrainProfile $terrain): CriterionScore
    {
        $exposure = $terrain->exposure();
        $coolness = $exposure->coolness();

        $altitudeShift = max(-0.22, min(0.22, ($terrain->elevationMeters - 1000) / 1000 * 0.22));
        $target = max(0.05, min(0.95, $species->coolPreference - $altitudeShift));

        $value = max(0.0, 100.0 - abs($coolness - $target) * 165);

        if ($exposure->isFlat()) {
            $value = max($value, 62.0);

            return new CriterionScore(
                Criterion::Exposure,
                $value,
                sprintf('Terrain peu pentu (%.0f°), l\'exposition joue peu', $terrain->slopeDegrees),
            );
        }

        $explanation = sprintf(
            'Versant %s à %.0f° de pente (fraîcheur %.2f, cible %.2f à %d m)',
            $exposure->cardinal(),
            $terrain->slopeDegrees,
            $coolness,
            $target,
            $terrain->elevationMeters,
        );

        return new CriterionScore(Criterion::Exposure, $value, $explanation);
    }

    private function cover(Species $species, TerrainProfile $terrain): CriterionScore
    {
        $value = $species->coverSuitability($terrain->cover) * 100;

        return new CriterionScore(
            Criterion::Cover,
            $value,
            sprintf('%s — hôtes recherchés : %s', $terrain->cover->label(), $species->hostTrees),
        );
    }

    private function moisture(Species $species, TerrainProfile $terrain): CriterionScore
    {
        $index = $terrain->moistureIndex();
        $value = max(0.0, 100.0 - abs($index - $species->moisturePreference) * 130);

        $descriptors = [];
        if ($terrain->curvature > 0.4) {
            $descriptors[] = 'creux de combe où l\'humidité s\'accumule';
        } elseif ($terrain->curvature < -0.4) {
            $descriptors[] = 'croupe convexe qui draine vite';
        }
        if ($terrain->waterDistanceMeters <= 250) {
            $descriptors[] = sprintf('ruisseau à %d m', $terrain->waterDistanceMeters);
        }

        return new CriterionScore(
            Criterion::Moisture,
            $value,
            $descriptors === []
                ? sprintf('Humidité topographique %.2f', $index)
                : sprintf('Humidité topographique %.2f — %s', $index, implode(', ', $descriptors)),
        );
    }

    private function edge(Species $species, TerrainProfile $terrain): CriterionScore
    {
        $value = $species->edgeAffinity->suitability($terrain->edgeDistanceMeters) * 100;

        $position = $terrain->edgeDistanceMeters >= 0
            ? sprintf('à %d m de la lisière, dans le boisement', $terrain->edgeDistanceMeters)
            : sprintf('hors boisement, forêt à %d m', abs($terrain->edgeDistanceMeters));

        return new CriterionScore(
            Criterion::Edge,
            $value,
            sprintf('%s — %s', ucfirst($position), lcfirst($species->edgeAffinity->label())),
        );
    }

    private function slope(Species $species, TerrainProfile $terrain): CriterionScore
    {
        $value = $species->slope->suitability($terrain->slopeDegrees) * 100;

        return new CriterionScore(
            Criterion::Slope,
            $value,
            sprintf('Pente de %.0f°', $terrain->slopeDegrees),
        );
    }
}
