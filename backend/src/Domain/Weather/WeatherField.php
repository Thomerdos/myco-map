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

        $trigger = $recent = $fortnight = $temperature = $humidity = $soil = 0.0;

        foreach ($weights as $index => $weight) {
            $share = $weight / $totalWeight;
            $conditions = $this->samples[$index]['conditions'];
            $trigger += $conditions->triggerRainMillimetres * $share;
            $recent += $conditions->recentRainMillimetres * $share;
            $fortnight += $conditions->fortnightRainMillimetres * $share;
            $temperature += $conditions->meanTemperatureCelsius * $share;
            $humidity += $conditions->relativeHumidityPercent * $share;
            $soil += $conditions->soilMoisture * $share;
        }

        return new WeatherConditions($trigger, $recent, $fortnight, $temperature, $humidity, $soil);
    }

    public function average(): WeatherConditions
    {
        $count = \count($this->samples);
        $trigger = $recent = $fortnight = $temperature = $humidity = $soil = 0.0;

        foreach ($this->samples as $sample) {
            $conditions = $sample['conditions'];
            $trigger += $conditions->triggerRainMillimetres;
            $recent += $conditions->recentRainMillimetres;
            $fortnight += $conditions->fortnightRainMillimetres;
            $temperature += $conditions->meanTemperatureCelsius;
            $humidity += $conditions->relativeHumidityPercent;
            $soil += $conditions->soilMoisture;
        }

        return new WeatherConditions(
            $trigger / $count,
            $recent / $count,
            $fortnight / $count,
            $temperature / $count,
            $humidity / $count,
            $soil / $count,
        );
    }
}
