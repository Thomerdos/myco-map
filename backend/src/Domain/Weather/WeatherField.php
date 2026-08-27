<?php

declare(strict_types=1);

namespace App\Domain\Weather;

use App\Domain\Geo\Coordinates;

/**
 * Spatially varying weather: a handful of samples across the area, interpolated so
 * valley and summit rainfall are not treated as identical.
 *
 * When samples come from a regular Open-Meteo lattice, lookup is O(1) bilinear over
 * the four neighbours. Irregular fields still fall back to inverse-distance weighting.
 */
final readonly class WeatherField
{
    /** @param list<array{point: Coordinates, conditions: WeatherConditions}> $samples */
    public function __construct(
        public array $samples,
        public bool $degraded = false,
        public ?WeatherLattice $lattice = null,
    ) {
        if ($samples === []) {
            throw new \InvalidArgumentException('Un champ météo requiert au moins un échantillon.');
        }
        if ($lattice !== null) {
            $expected = $lattice->samplesPerAxis * $lattice->samplesPerAxis;
            if (\count($samples) !== $expected) {
                throw new \InvalidArgumentException(sprintf(
                    'La grille météo attend %d échantillons, %d fournis.',
                    $expected,
                    \count($samples),
                ));
            }
        }
    }

    public function at(Coordinates $point): WeatherConditions
    {
        if (\count($this->samples) === 1) {
            return $this->samples[0]['conditions'];
        }

        if ($this->lattice !== null) {
            return $this->bilinearAt($point);
        }

        return $this->inverseDistanceAt($point);
    }

    /**
     * Nearest lattice (or sole) sample without allocating a blended WeatherConditions.
     * Dense map loops should prefer this over {@see at()}.
     */
    public function nearest(Coordinates $point): WeatherConditions
    {
        if (\count($this->samples) === 1) {
            return $this->samples[0]['conditions'];
        }

        if ($this->lattice !== null) {
            return $this->samples[$this->lattice->nearestIndex($point)]['conditions'];
        }

        $bestIndex = 0;
        $bestDistance = $point->distanceTo($this->samples[0]['point']);
        foreach ($this->samples as $index => $sample) {
            if ($index === 0) {
                continue;
            }
            $distance = $point->distanceTo($sample['point']);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestIndex = $index;
            }
        }

        return $this->samples[$bestIndex]['conditions'];
    }

    public function average(): WeatherConditions
    {
        $count = \count($this->samples);
        $trigger = $recent = $fortnight = $temperature = $humidity = $soil = $soaking = 0.0;
        $accumulated = $preceding = $rainSince = $litter = 0.0;
        $daysSum = 0;
        $daysCount = 0;
        $mergedSpells = [];

        foreach ($this->samples as $sample) {
            $conditions = $sample['conditions'];
            $trigger += $conditions->triggerRainMillimetres;
            $recent += $conditions->recentRainMillimetres;
            $fortnight += $conditions->fortnightRainMillimetres;
            $temperature += $conditions->meanTemperatureCelsius;
            $humidity += $conditions->relativeHumidityPercent;
            $soil += $conditions->soilMoisture;
            $soaking += $conditions->soakingRainMillimetres;
            $accumulated += $conditions->accumulatedRainMillimetres;
            $preceding += $conditions->precedingDryMillimetres;
            $rainSince += $conditions->rainSinceSoakingMillimetres;
            $litter += $conditions->litterSoilMoisture;
            if ($conditions->daysSinceSoakingRain !== null) {
                $daysSum += $conditions->daysSinceSoakingRain;
                $daysCount++;
            }
            foreach ($conditions->soakingSpells() as $spell) {
                $key = $spell['daysSince'];
                $current = $mergedSpells[$key] ?? ['millimetres' => 0.0, 'rainAfter' => 0.0, 'temperature' => null];
                if ($spell['millimetres'] > $current['millimetres']) {
                    $mergedSpells[$key] = [
                        'millimetres' => $spell['millimetres'],
                        'rainAfter' => (float) ($spell['rainAfter'] ?? 0.0),
                        'temperature' => isset($spell['temperature']) ? (float) $spell['temperature'] : null,
                    ];
                }
            }
        }

        ksort($mergedSpells);
        $events = [];
        foreach (array_reverse($mergedSpells, true) as $daysSince => $spell) {
            $events[] = [
                'daysSince' => (int) $daysSince,
                'millimetres' => $spell['millimetres'],
                'rainAfter' => $spell['rainAfter'],
                'temperature' => $spell['temperature'],
            ];
        }

        return new WeatherConditions(
            $trigger / $count,
            $recent / $count,
            $fortnight / $count,
            $temperature / $count,
            $humidity / $count,
            $soil / $count,
            $daysCount > 0 ? (int) round($daysSum / $daysCount) : null,
            $soaking / $count,
            $accumulated / $count,
            $preceding / $count,
            $events,
            $rainSince / $count,
            $litter / $count,
        );
    }

    private function bilinearAt(Coordinates $point): WeatherConditions
    {
        $corners = $this->lattice->bilinearCorners($point);
        if ($corners === []) {
            return $this->samples[$this->lattice->nearestIndex($point)]['conditions'];
        }

        if (\count($corners) === 1) {
            return $this->samples[$corners[0][0]]['conditions'];
        }

        $weights = [];
        $totalWeight = 0.0;
        $nearestIndex = $corners[0][0];
        $nearestWeight = $corners[0][1];

        foreach ($corners as [$index, $weight]) {
            $weights[$index] = $weight;
            $totalWeight += $weight;
            if ($weight > $nearestWeight) {
                $nearestWeight = $weight;
                $nearestIndex = $index;
            }
        }

        return $this->blend($weights, $totalWeight, $nearestIndex);
    }

    private function inverseDistanceAt(Coordinates $point): WeatherConditions
    {
        $weights = [];
        $totalWeight = 0.0;

        foreach ($this->samples as $index => $sample) {
            $distance = $point->distanceTo($sample['point']);
            if ($distance < 1.0) {
                return $sample['conditions'];
            }
            $weight = 1 / ($distance ** 2);
            $weights[$index] = $weight;
            $totalWeight += $weight;
        }

        $nearestIndex = array_key_first($weights) ?? 0;
        $nearestWeight = $weights[$nearestIndex] ?? 0.0;
        foreach ($weights as $index => $weight) {
            if ($weight > $nearestWeight) {
                $nearestWeight = $weight;
                $nearestIndex = $index;
            }
        }

        return $this->blend($weights, $totalWeight, $nearestIndex);
    }

    /**
     * @param array<int, float> $weights
     */
    private function blend(array $weights, float $totalWeight, int $nearestIndex): WeatherConditions
    {
        $trigger = $recent = $fortnight = $temperature = $humidity = $soil = $soaking = 0.0;
        $accumulated = $preceding = $rainSince = $litter = 0.0;
        $daysWeighted = 0.0;
        $daysWeight = 0.0;

        foreach ($weights as $index => $weight) {
            $share = $weight / $totalWeight;
            $conditions = $this->samples[$index]['conditions'];
            $trigger += $conditions->triggerRainMillimetres * $share;
            $recent += $conditions->recentRainMillimetres * $share;
            $fortnight += $conditions->fortnightRainMillimetres * $share;
            $temperature += $conditions->meanTemperatureCelsius * $share;
            $humidity += $conditions->relativeHumidityPercent * $share;
            $soil += $conditions->soilMoisture * $share;
            $soaking += $conditions->soakingRainMillimetres * $share;
            $accumulated += $conditions->accumulatedRainMillimetres * $share;
            $preceding += $conditions->precedingDryMillimetres * $share;
            $rainSince += $conditions->rainSinceSoakingMillimetres * $share;
            $litter += $conditions->litterSoilMoisture * $share;
            if ($conditions->daysSinceSoakingRain !== null) {
                $daysWeighted += $conditions->daysSinceSoakingRain * $share;
                $daysWeight += $share;
            }
        }

        return new WeatherConditions(
            $trigger,
            $recent,
            $fortnight,
            $temperature,
            $humidity,
            $soil,
            $daysWeight > 0 ? (int) round($daysWeighted / $daysWeight) : null,
            $soaking,
            $accumulated,
            $preceding,
            $this->samples[$nearestIndex]['conditions']->soakingEvents,
            $rainSince,
            $litter,
        );
    }
}
