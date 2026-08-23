<?php

declare(strict_types=1);

namespace App\Domain\Weather;

/**
 * Weather windows that drive fruiting, plus the age of the last soaking rain.
 *
 * Mycelium does not fruit the day after a storm: boletes around Grenoble typically
 * need roughly a week to a fortnight after a marked rainy spell. Keeping
 * {@see $daysSinceSoakingRain} explicit is what lets the score stay honest in the
 * days right after a drought-breaking rain.
 */
final readonly class WeatherConditions
{
    /**
     * @param float $accumulatedRainMillimetres total over the last 26 days: daily monitoring of
     *                                          Boletus edulis finds fruiting rises with rain
     *                                          accumulated over roughly that window, well beyond
     *                                          the single triggering spell
     * @param float $precedingDryMillimetres    rain over the fortnight *before* the trigger
     *                                          window, used to detect a drought broken by the
     *                                          spell — those events fruit best
     */
    public function __construct(
        public float $triggerRainMillimetres,
        public float $recentRainMillimetres,
        public float $fortnightRainMillimetres,
        public float $meanTemperatureCelsius,
        public float $relativeHumidityPercent,
        public float $soilMoisture,
        public ?int $daysSinceSoakingRain = null,
        public float $soakingRainMillimetres = 0.0,
        public float $accumulatedRainMillimetres = 0.0,
        public float $precedingDryMillimetres = 0.0,
    ) {
    }

    /**
     * A marked spell that ends a dry fortnight produces more than the same rain falling on
     * already-wet ground.
     */
    public function brokeDrySpell(): bool
    {
        return $this->precedingDryMillimetres < 10.0 && $this->soakingRainMillimetres >= 20.0;
    }

    public function label(): string
    {
        if ($this->daysSinceSoakingRain === null || $this->soakingRainMillimetres < 15.0) {
            return $this->fortnightRainMillimetres < 10.0 ? 'Sec' : 'Sans pluie déclenchante claire';
        }

        return match (true) {
            $this->daysSinceSoakingRain <= 3 => 'Incubation — trop tôt pour la pousse',
            $this->daysSinceSoakingRain <= 6 => 'Mycélium en démarrage',
            $this->daysSinceSoakingRain <= 14 => 'Fenêtre de pousse probable',
            $this->daysSinceSoakingRain <= 20 => 'Fin de poussée',
            default => 'Poussée passée',
        };
    }

    /** @return array<string, float|int|bool|string|null> */
    public function toArray(): array
    {
        return [
            'triggerRain' => round($this->triggerRainMillimetres, 1),
            'recentRain' => round($this->recentRainMillimetres, 1),
            'fortnightRain' => round($this->fortnightRainMillimetres, 1),
            'temperature' => round($this->meanTemperatureCelsius, 1),
            'humidity' => round($this->relativeHumidityPercent),
            'soilMoisture' => round($this->soilMoisture, 3),
            'daysSinceSoakingRain' => $this->daysSinceSoakingRain,
            'soakingRain' => round($this->soakingRainMillimetres, 1),
            'accumulatedRain' => round($this->accumulatedRainMillimetres, 1),
            'brokeDrySpell' => $this->brokeDrySpell(),
            'label' => $this->label(),
        ];
    }
}
