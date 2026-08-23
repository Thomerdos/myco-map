<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

use App\Domain\Terrain\TerrainProfile;
use App\Domain\Weather\WeatherConditions;

/**
 * Transparent, rule-based habitat model.
 *
 * Two entry points on purpose: {@see evaluate()} returns only the number and is cheap
 * enough to run on every cell of a viewport, while {@see score()} additionally builds
 * the sentences explaining each criterion and is reserved for the handful of cells the
 * user actually inspects.
 */
final readonly class SuitabilityCalculator
{
    public function evaluate(
        Species $species,
        TerrainProfile $terrain,
        WeatherConditions $weather,
        SeasonAssessment $season,
    ): float {
        $total = $season->criterionScore->weighted()
            + $this->weatherValue($weather) * Criterion::Weather->weight()
            + $this->altitudeValue($species, $terrain) * Criterion::Altitude->weight()
            + $this->exposureValue($species, $terrain) * Criterion::Exposure->weight()
            + $this->coverValue($species, $terrain) * Criterion::Cover->weight()
            + $this->moistureValue($species, $terrain) * Criterion::Moisture->weight()
            + $this->edgeValue($species, $terrain) * Criterion::Edge->weight()
            + $this->slopeValue($species, $terrain) * Criterion::Slope->weight();

        return $this->applyCaps($total, $species, $terrain, $season);
    }

    public function score(
        Species $species,
        TerrainProfile $terrain,
        WeatherConditions $weather,
        SeasonAssessment $season,
    ): SuitabilityScore {
        $breakdown = [
            $season->criterionScore,
            new CriterionScore(
                Criterion::Weather,
                $this->weatherValue($weather),
                $this->explainWeather($weather),
            ),
            new CriterionScore(
                Criterion::Altitude,
                $this->altitudeValue($species, $terrain),
                $this->explainAltitude($species, $terrain),
            ),
            new CriterionScore(
                Criterion::Exposure,
                $this->exposureValue($species, $terrain),
                $this->explainExposure($species, $terrain),
            ),
            new CriterionScore(
                Criterion::Cover,
                $this->coverValue($species, $terrain),
                sprintf('%s — hôtes recherchés : %s', $terrain->cover->label(), $species->hostTrees),
            ),
            new CriterionScore(
                Criterion::Moisture,
                $this->moistureValue($species, $terrain),
                $this->explainMoisture($terrain),
            ),
            new CriterionScore(
                Criterion::Edge,
                $this->edgeValue($species, $terrain),
                $this->explainEdge($species, $terrain),
            ),
            new CriterionScore(
                Criterion::Slope,
                $this->slopeValue($species, $terrain),
                sprintf('Pente de %.0f°', $terrain->slopeDegrees),
            ),
        ];

        $total = 0.0;
        foreach ($breakdown as $criterionScore) {
            $total += $criterionScore->weighted();
        }

        $total = $this->applyCaps($total, $species, $terrain, $season);

        return new SuitabilityScore(
            $total,
            SuitabilityLevel::fromScore($total),
            $breakdown,
            $season->isInSeason(),
        );
    }

    private function applyCaps(
        float $total,
        Species $species,
        TerrainProfile $terrain,
        SeasonAssessment $season,
    ): float {
        if ($species->requiresForest && !$terrain->cover->isForest()) {
            $total = min($total, 18.0);
        }
        if (!$season->isInSeason()) {
            $total = min($total, 38.0);
        }

        return max(0.0, min(100.0, $total));
    }

    private function weatherValue(WeatherConditions $weather): float
    {
        $trigger = match (true) {
            $weather->triggerRainMillimetres >= 45 => 100.0,
            $weather->triggerRainMillimetres >= 30 => 88.0,
            $weather->triggerRainMillimetres >= 20 => 72.0,
            $weather->triggerRainMillimetres >= 12 => 55.0,
            $weather->triggerRainMillimetres >= 6 => 35.0,
            default => 12.0,
        };

        $recent = match (true) {
            $weather->recentRainMillimetres >= 25 => 70.0,
            $weather->recentRainMillimetres >= 4 => 100.0,
            $weather->recentRainMillimetres >= 1 => 80.0,
            default => 45.0,
        };

        $temperature = match (true) {
            $weather->meanTemperatureCelsius >= 9 && $weather->meanTemperatureCelsius <= 17 => 100.0,
            $weather->meanTemperatureCelsius >= 6 && $weather->meanTemperatureCelsius < 9 => 78.0,
            $weather->meanTemperatureCelsius > 17 && $weather->meanTemperatureCelsius <= 21 => 70.0,
            $weather->meanTemperatureCelsius > 21 => 35.0,
            $weather->meanTemperatureCelsius >= 2 => 45.0,
            default => 12.0,
        };

        $humidity = min(100.0, max(20.0, ($weather->relativeHumidityPercent - 45) * 2.2));

        return $trigger * 0.46 + $recent * 0.20 + $temperature * 0.22 + $humidity * 0.12;
    }

    private function altitudeValue(Species $species, TerrainProfile $terrain): float
    {
        return $species->altitude->suitability($terrain->elevationMeters) * 100;
    }

    /**
     * In mountains the preferred orientation shifts with altitude: cool north-facing
     * slopes stay productive low down, while higher up the warmer southern slopes
     * catch up.
     */
    private function exposureValue(Species $species, TerrainProfile $terrain): float
    {
        $exposure = $terrain->exposure();
        $value = max(0.0, 100.0 - abs($exposure->coolness() - $this->coolTarget($species, $terrain)) * 165);

        return $exposure->isFlat() ? max($value, 62.0) : $value;
    }

    private function coolTarget(Species $species, TerrainProfile $terrain): float
    {
        $altitudeShift = max(-0.22, min(0.22, ($terrain->elevationMeters - 1000) / 1000 * 0.22));

        return max(0.05, min(0.95, $species->coolPreference - $altitudeShift));
    }

    private function coverValue(Species $species, TerrainProfile $terrain): float
    {
        return $species->coverSuitability($terrain->cover) * 100;
    }

    private function moistureValue(Species $species, TerrainProfile $terrain): float
    {
        return max(0.0, 100.0 - abs($terrain->moistureIndex() - $species->moisturePreference) * 130);
    }

    private function edgeValue(Species $species, TerrainProfile $terrain): float
    {
        return $species->edgeAffinity->suitability($terrain->edgeDistanceMeters) * 100;
    }

    private function slopeValue(Species $species, TerrainProfile $terrain): float
    {
        return $species->slope->suitability($terrain->slopeDegrees) * 100;
    }

    private function explainWeather(WeatherConditions $weather): string
    {
        return sprintf(
            '%.0f mm de pluie déclenchante (J-14 à J-5), %.0f mm ces 5 derniers jours, %.1f °C de moyenne',
            $weather->triggerRainMillimetres,
            $weather->recentRainMillimetres,
            $weather->meanTemperatureCelsius,
        );
    }

    private function explainAltitude(Species $species, TerrainProfile $terrain): string
    {
        $band = $species->altitude;
        $membership = $band->suitability($terrain->elevationMeters);

        return match (true) {
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
    }

    private function explainExposure(Species $species, TerrainProfile $terrain): string
    {
        $exposure = $terrain->exposure();

        if ($exposure->isFlat()) {
            return sprintf('Terrain peu pentu (%.0f°), l\'exposition joue peu', $terrain->slopeDegrees);
        }

        return sprintf(
            'Versant %s à %.0f° de pente (fraîcheur %.2f, cible %.2f à %d m)',
            $exposure->cardinal(),
            $terrain->slopeDegrees,
            $exposure->coolness(),
            $this->coolTarget($species, $terrain),
            $terrain->elevationMeters,
        );
    }

    private function explainMoisture(TerrainProfile $terrain): string
    {
        $descriptors = [];

        if ($terrain->curvature > 0.4) {
            $descriptors[] = 'creux de combe où l\'humidité s\'accumule';
        } elseif ($terrain->curvature < -0.4) {
            $descriptors[] = 'croupe convexe qui draine vite';
        }
        if ($terrain->waterDistanceMeters <= 250) {
            $descriptors[] = sprintf('ruisseau à %d m', $terrain->waterDistanceMeters);
        }

        $index = $terrain->moistureIndex();

        return $descriptors === []
            ? sprintf('Humidité topographique %.2f', $index)
            : sprintf('Humidité topographique %.2f — %s', $index, implode(', ', $descriptors));
    }

    private function explainEdge(Species $species, TerrainProfile $terrain): string
    {
        $position = $terrain->edgeDistanceMeters >= 0
            ? sprintf('À %d m de la lisière, dans le boisement', $terrain->edgeDistanceMeters)
            : sprintf('Hors boisement, forêt à %d m', abs($terrain->edgeDistanceMeters));

        return sprintf('%s — %s', $position, lcfirst($species->edgeAffinity->label()));
    }
}
