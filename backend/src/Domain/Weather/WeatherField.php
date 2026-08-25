<?php

declare(strict_types=1);

namespace App\Domain\Weather;

use App\Domain\Geo\Coordinates;

/**
 * Spatially varying weather: a handful of samples across the area, interpolated so
 * valley and summit rainfall are not treated as identical.
 */
final readonly class WeatherField
{
    /** @param list<array{point: Coordinates, conditions: WeatherConditions}> $samples */
    public function __construct(
        public array $samples,
        public bool $degraded = false,
    ) {
        if ($samples === []) {
            throw new \InvalidArgumentException('Un champ météo requiert au moins un échantillon.');
        }
    }

    public function at(Coordinates $point): WeatherConditions
    {
        if (\count($this->samples) === 1) {
            return $this->samples[0]['conditions'];
        }

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

        $trigger = $recent = $fortnight = $temperature = $humidity = $soil = $soaking = 0.0;
        $accumulated = $preceding = $rainSince = $litter = 0.0;
        $daysWeighted = 0.0;
        $daysWeight = 0.0;
        $nearestIndex = array_key_first($weights) ?? 0;
        $nearestWeight = $weights[$nearestIndex] ?? 0.0;

        foreach ($weights as $index => $weight) {
            if ($weight > $nearestWeight) {
                $nearestWeight = $weight;
                $nearestIndex = $index;
            }
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
}
