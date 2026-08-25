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
     * @param float $rainSinceSoakingMillimetres rain after the latest soaking spell ended (not
     *                                          including the storm days themselves)
     * @param float $litterSoilMoisture         volumetric 3–9 cm, more stable than 0–1 cm
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
        /** @var list<array{daysSince: int, millimetres: float, rainAfter?: float, temperature?: float}> */
        public array $soakingEvents = [],
        public float $rainSinceSoakingMillimetres = 0.0,
        public float $litterSoilMoisture = 0.30,
    ) {
    }

    /**
     * Every soaking spell still in the archive, newest first. Falls back to the single
     * last-event fields when a caller has not populated the list.
     *
     * @return list<array{daysSince: int, millimetres: float, rainAfter?: float, temperature?: float}>
     */
    public function soakingSpells(): array
    {
        if ($this->soakingEvents !== []) {
            return $this->soakingEvents;
        }

        if ($this->daysSinceSoakingRain !== null && $this->soakingRainMillimetres >= 15.0) {
            return [[
                'daysSince' => $this->daysSinceSoakingRain,
                'millimetres' => $this->soakingRainMillimetres,
                'rainAfter' => $this->rainSinceSoakingMillimetres,
            ]];
        }

        return [];
    }

    /**
     * A marked spell that ends a dry fortnight produces more than the same rain falling on
     * already-wet ground.
     */
    public function brokeDrySpell(): bool
    {
        return $this->precedingDryMillimetres < 10.0 && $this->soakingRainMillimetres >= 20.0;
    }

    /**
     * Little follow-up rain after a spell and a dry 3–9 cm profile: primordia abort.
     * Opposite of {@see brokeDrySpell()}.
     */
    public function driedOutAfterSoaking(?float $rainAfterMillimetres = null): bool
    {
        if ($this->daysSinceSoakingRain === null || $this->soakingRainMillimetres < 15.0) {
            return false;
        }

        $rainAfter = $rainAfterMillimetres ?? $this->rainSinceSoakingMillimetres;

        return $rainAfter < 8.0 && $this->litterSoilMoisture < 0.18;
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
            'rainSinceSoaking' => round($this->rainSinceSoakingMillimetres, 1),
            'litterSoilMoisture' => round($this->litterSoilMoisture, 3),
            'brokeDrySpell' => $this->brokeDrySpell(),
            'driedOutAfterSoaking' => $this->driedOutAfterSoaking(),
            'label' => $this->label(),
            'soakingEvents' => array_map(
                static fn (array $spell): array => [
                    'daysSince' => $spell['daysSince'],
                    'millimetres' => round($spell['millimetres'], 1),
                    'rainAfter' => round((float) ($spell['rainAfter'] ?? 0.0), 1),
                ],
                $this->soakingSpells(),
            ),
        ];
    }
}
