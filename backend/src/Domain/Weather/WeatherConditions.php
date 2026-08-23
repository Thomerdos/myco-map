<?php

declare(strict_types=1);

namespace App\Domain\Weather;

/**
 * Weather aggregated over the windows that actually matter for fruiting:
 * a soaking rain 5 to 14 days ago triggers the flush, recent rain keeps the
 * litter humid while it develops.
 */
final readonly class WeatherConditions
{
    public function __construct(
        public float $triggerRainMillimetres,
        public float $recentRainMillimetres,
        public float $fortnightRainMillimetres,
        public float $meanTemperatureCelsius,
        public float $relativeHumidityPercent,
        public float $soilMoisture,
    ) {
    }

    public function label(): string
    {
        return match (true) {
            $this->triggerRainMillimetres >= 30 && $this->recentRainMillimetres >= 3 => 'Très favorable',
            $this->triggerRainMillimetres >= 20 => 'Favorable',
            $this->triggerRainMillimetres >= 8 => 'Moyen',
            default => 'Sec',
        };
    }

    /** @return array<string, float|string> */
    public function toArray(): array
    {
        return [
            'triggerRain' => round($this->triggerRainMillimetres, 1),
            'recentRain' => round($this->recentRainMillimetres, 1),
            'fortnightRain' => round($this->fortnightRainMillimetres, 1),
            'temperature' => round($this->meanTemperatureCelsius, 1),
            'humidity' => round($this->relativeHumidityPercent),
            'soilMoisture' => round($this->soilMoisture, 3),
            'label' => $this->label(),
        ];
    }
}
