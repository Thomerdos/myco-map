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
            + $this->weatherValue($species, $weather) * Criterion::Weather->weight()
            + $this->altitudeValue($species, $terrain) * Criterion::Altitude->weight()
            + $this->exposureValue($species, $terrain) * Criterion::Exposure->weight()
            + $this->coverValue($species, $terrain) * Criterion::Cover->weight()
            + $this->moistureValue($species, $terrain) * Criterion::Moisture->weight()
            + $this->edgeValue($species, $terrain) * Criterion::Edge->weight()
            + $this->slopeValue($species, $terrain) * Criterion::Slope->weight();

        return $this->applyCaps($total, $species, $terrain, $season, $weather);
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
                $this->weatherValue($species, $weather),
                $this->explainWeather($species, $weather),
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
                $this->explainSlope($species, $terrain),
            ),
        ];

        $total = 0.0;
        foreach ($breakdown as $criterionScore) {
            $total += $criterionScore->weighted();
        }

        $total = $this->applyCaps($total, $species, $terrain, $season, $weather);

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
        WeatherConditions $weather,
    ): float {
        if ($species->requiresForest && !$terrain->cover->isForest()) {
            $total = min($total, 18.0);
        }
        if (!$season->isInSeason()) {
            $total = min($total, 38.0);
        }

        // Habitat alone must not read as "go now" while fruitbodies are still incubating.
        $phenology = $this->flushPhenology($species, $weather);
        if ($phenology < 25.0) {
            $total = min($total, 48.0);
        } elseif ($phenology < 45.0) {
            $total = min($total, 62.0);
        }

        return max(0.0, min(100.0, $total));
    }

    /**
     * Weather score is dominated by flush phenology: a soaking rain is necessary but
     * fruitbodies lag behind it. Temperature, air humidity and measured soil moisture
     * only modulate once the clock since that rain is in the species' fruiting window.
     */
    private function weatherValue(Species $species, WeatherConditions $weather): float
    {
        $phenology = $this->flushPhenology($species, $weather);
        $supply = $this->waterSupply($weather);

        $recent = match (true) {
            $weather->daysSinceSoakingRain !== null && $weather->daysSinceSoakingRain <= 3
                => 40.0, // the soaking itself is still falling in the "recent" bucket — do not treat it as ideal litter rain
            $weather->recentRainMillimetres >= 40 => 55.0,
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

        // Phenology × water supply is the gate; ambient conditions only fine-tune.
        return $phenology * ($supply / 100.0) * 0.54
            + $recent * 0.10
            + $temperature * 0.16
            + $humidity * 0.08
            + $this->soilMoistureValue($weather) * 0.12;
    }

    /**
     * Water available to the mycelium: the triggering spell, plus the four-week
     * accumulation that published yield models relate fruiting to, boosted when the spell
     * broke a dry period.
     */
    private function waterSupply(WeatherConditions $weather): float
    {
        $supply = $this->soakingStrength($weather) * 0.65
            + $this->accumulationStrength($weather) * 0.35;

        if ($weather->brokeDrySpell()) {
            $supply *= 1.15;
        }

        return min(100.0, $supply);
    }

    private function soakingStrength(WeatherConditions $weather): float
    {
        $mm = max($weather->soakingRainMillimetres, $weather->triggerRainMillimetres);

        return match (true) {
            $mm >= 45 => 100.0,
            $mm >= 30 => 90.0,
            $mm >= 20 => 78.0,
            $mm >= 15 => 62.0,
            $mm >= 10 => 40.0,
            default => 18.0,
        };
    }

    /** Fruiting increases roughly linearly with rain accumulated over about four weeks. */
    private function accumulationStrength(WeatherConditions $weather): float
    {
        $mm = max($weather->accumulatedRainMillimetres, $weather->fortnightRainMillimetres);

        return min(100.0, $mm / 80.0 * 100.0);
    }

    /**
     * Remote-sensed soil moisture rivals rainfall as a predictor of yields, so the measured
     * value is scored directly rather than inferred from precipitation alone. Units are
     * volumetric (m³/m³) for the top centimetre of soil.
     */
    private function soilMoistureValue(WeatherConditions $weather): float
    {
        $moisture = $weather->soilMoisture;

        return match (true) {
            $moisture >= 0.24 && $moisture <= 0.42 => 100.0,
            $moisture > 0.42 => 78.0,
            $moisture >= 0.18 => 72.0,
            $moisture >= 0.12 => 45.0,
            default => 18.0,
        };
    }

    /**
     * Readiness of fruitbodies given days since the end of the last soaking spell.
     * Peaks around {@see Species::$flushDelayPeakDays}; near zero for the first days.
     */
    private function flushPhenology(Species $species, WeatherConditions $weather): float
    {
        $days = $weather->daysSinceSoakingRain;
        if ($days === null || $weather->soakingRainMillimetres < 15.0) {
            // No clear soaking event: leftover humidity may allow scraps, not a flush.
            return $weather->fortnightRainMillimetres >= 20.0 ? 22.0 : 10.0;
        }

        $min = $species->flushDelayMinDays;
        $peak = $species->flushDelayPeakDays;
        $max = $species->flushDelayMaxDays;

        if ($days <= 2) {
            return 8.0;
        }
        if ($days < $min) {
            return 8.0 + (32.0 - 8.0) * ($days - 2) / max(1, $min - 2);
        }
        if ($days <= $peak) {
            return 32.0 + (100.0 - 32.0) * ($days - $min) / max(1, $peak - $min);
        }
        if ($days <= $max) {
            return 100.0 - 40.0 * ($days - $peak) / max(1, $max - $peak);
        }

        return max(12.0, 60.0 - ($days - $max) * 5.0);
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

    private function explainWeather(Species $species, WeatherConditions $weather): string
    {
        if ($weather->daysSinceSoakingRain === null || $weather->soakingRainMillimetres < 15.0) {
            return sprintf(
                'Pas d\'épisode marquant récent (%.0f mm sur 15 j). Sans pluie déclenchante, la pousse reste improbable — %.1f °C',
                $weather->fortnightRainMillimetres,
                $weather->meanTemperatureCelsius,
            );
        }

        $days = $weather->daysSinceSoakingRain;
        $phase = match (true) {
            $days <= 3 => sprintf(
                'il y a %d j seulement : le mycélium démarre, trop tôt pour trouver des carpophores (pic attendu vers J+%d pour %s)',
                $days,
                $species->flushDelayPeakDays,
                lcfirst($species->commonName),
            ),
            $days < $species->flushDelayMinDays => sprintf(
                'il y a %d j : incubation en cours, premières sorties possibles à partir de J+%d',
                $days,
                $species->flushDelayMinDays,
            ),
            $days <= $species->flushDelayPeakDays => sprintf(
                'il y a %d j : dans la fenêtre de pousse (idéal vers J+%d)',
                $days,
                $species->flushDelayPeakDays,
            ),
            $days <= $species->flushDelayMaxDays => sprintf(
                'il y a %d j : fin de la poussée liée à cet épisode (fenêtre jusqu\'à J+%d)',
                $days,
                $species->flushDelayMaxDays,
            ),
            default => sprintf(
                'il y a %d j : la poussée de cet épisode est largement derrière nous',
                $days,
            ),
        };

        return sprintf(
            'Épisode de %.0f mm %s · %.0f mm sur les 5 derniers jours, %.1f °C',
            $weather->soakingRainMillimetres,
            $phase,
            $weather->recentRainMillimetres,
            $weather->meanTemperatureCelsius,
        );
    }

    private function explainSlope(Species $species, TerrainProfile $terrain): string
    {
        $slope = $terrain->slopeDegrees;
        $band = $species->slope;
        $reading = match (true) {
            $slope >= $band->maximum => 'trop raide : sol mince et lessivage fort, litière emportée',
            $slope > $band->toleratedUpTo => sprintf(
                'au-delà de la pente tolérée (%.0f°) : la matière organique et l\'eau dévalent',
                $band->toleratedUpTo,
            ),
            $slope <= 5.0 => 'terrain porteur : le sol garde son épaisseur et sa litière',
            default => sprintf(
                'pente modérée, encore favorable jusqu\'à %.0f°',
                $band->toleratedUpTo,
            ),
        };

        return sprintf('Pente de %.0f° — %s', $slope, $reading);
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
