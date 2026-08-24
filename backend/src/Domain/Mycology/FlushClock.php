<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

use App\Domain\Weather\WeatherConditions;

/**
 * Maps days since each soaking spell onto a fruiting curve. Several spells can be
 * "alive" at once: a new storm does not erase a flush that is already out.
 *
 * Delays stretch when it is colder than ~13 °C (slower primordia) and compress slightly
 * when it is warmer, within a narrow band so a hot week cannot skip incubation.
 */
final readonly class FlushClock
{
    /**
     * @return array{min: int, peak: int, max: int, persist: int}
     */
    public static function timetable(Species $species, WeatherConditions $weather): array
    {
        $factor = max(0.82, min(1.40, 1.0 + (13.0 - $weather->meanTemperatureCelsius) / 28.0));

        $min = max(3, (int) round($species->flushDelayMinDays * $factor));
        $peak = max($min + 1, (int) round($species->flushDelayPeakDays * $factor));
        $max = max($peak + 1, (int) round($species->flushDelayMaxDays * $factor));
        $persist = max(1, (int) round($species->flushPersistDays * $factor));

        return ['min' => $min, 'peak' => $peak, 'max' => $max, 'persist' => $persist];
    }

    public static function phenology(Species $species, WeatherConditions $weather): float
    {
        $best = 0.0;
        $any = false;

        foreach ($weather->soakingSpells() as $spell) {
            if ($spell['millimetres'] < 15.0) {
                continue;
            }
            $any = true;
            $best = max($best, self::curve($species, $weather, $spell['daysSince']));
        }

        if (!$any) {
            return $weather->fortnightRainMillimetres >= 20.0 ? 22.0 : 10.0;
        }

        return $best;
    }

    /**
     * Spell that currently contributes the most to fruiting (not necessarily the last rain).
     *
     * @return array{daysSince: int, millimetres: float, phenology: float, phase: string}|null
     */
    public static function leadingSpell(Species $species, WeatherConditions $weather): ?array
    {
        $leading = null;

        foreach ($weather->soakingSpells() as $spell) {
            if ($spell['millimetres'] < 15.0) {
                continue;
            }
            $value = self::curve($species, $weather, $spell['daysSince']);
            if ($leading === null || $value > $leading['phenology']) {
                $leading = [
                    'daysSince' => $spell['daysSince'],
                    'millimetres' => $spell['millimetres'],
                    'phenology' => $value,
                    'phase' => self::phase($species, $weather, $spell['daysSince']),
                ];
            }
        }

        return $leading;
    }

    public static function label(Species $species, WeatherConditions $weather): string
    {
        $leading = self::leadingSpell($species, $weather);
        if ($leading === null) {
            return $weather->fortnightRainMillimetres < 10.0 ? 'Sec' : 'Sans pluie déclenchante claire';
        }

        $times = self::timetable($species, $weather);
        $name = lcfirst($species->commonName);

        return match ($leading['phase']) {
            'incubating' => sprintf('Incubation %s — sorties vers J+%d', $name, $times['min']),
            'starting' => sprintf('Pousse %s en cours — pic vers J+%d', $name, $times['peak']),
            'peak' => sprintf('Pic de pousse — %s', $name),
            'declining' => sprintf('Fin de poussée %s (jusqu\'à J+%d)', $name, $times['max']),
            'lingering' => sprintf('Encore cueillable — %s', $name),
            default => sprintf('Poussée %s passée', $name),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function decorate(WeatherConditions $weather, Species $species): array
    {
        $leading = self::leadingSpell($species, $weather);
        $payload = $weather->toArray();
        $payload['label'] = self::label($species, $weather);
        $payload['flushDaysSince'] = $leading['daysSince'] ?? null;
        $payload['flushPhase'] = $leading['phase'] ?? 'none';
        $payload['flushMillimetres'] = isset($leading) ? round($leading['millimetres'], 1) : null;

        return $payload;
    }

    public static function explain(Species $species, WeatherConditions $weather): string
    {
        $leading = self::leadingSpell($species, $weather);
        $times = self::timetable($species, $weather);
        $latest = $weather->soakingSpells()[0] ?? null;

        if ($leading === null) {
            return sprintf(
                'Pas d\'épisode marquant récent (%.0f mm sur 15 j). Sans pluie déclenchante, la pousse reste improbable — %.1f °C',
                $weather->fortnightRainMillimetres,
                $weather->meanTemperatureCelsius,
            );
        }

        $phase = match ($leading['phase']) {
            'incubating' => sprintf(
                'il y a %d j : incubation, premières sorties vers J+%d',
                $leading['daysSince'],
                $times['min'],
            ),
            'starting' => sprintf(
                'il y a %d j : pousse en cours (pic vers J+%d)',
                $leading['daysSince'],
                $times['peak'],
            ),
            'peak' => sprintf(
                'il y a %d j : pic de pousse (idéal vers J+%d)',
                $leading['daysSince'],
                $times['peak'],
            ),
            'declining' => sprintf(
                'il y a %d j : fin de poussée (fenêtre jusqu\'à J+%d)',
                $leading['daysSince'],
                $times['max'],
            ),
            'lingering' => sprintf(
                'il y a %d j : carpophores encore cueillables quelques jours',
                $leading['daysSince'],
            ),
            default => sprintf(
                'il y a %d j : cette poussée est derrière nous',
                $leading['daysSince'],
            ),
        };

        $text = sprintf(
            'Épisode de %.0f mm %s · %.0f mm sur les 5 derniers jours, %.1f °C',
            $leading['millimetres'],
            $phase,
            $weather->recentRainMillimetres,
            $weather->meanTemperatureCelsius,
        );

        if (
            $latest !== null
            && $latest['daysSince'] !== $leading['daysSince']
            && $latest['millimetres'] >= 15.0
        ) {
            $text .= sprintf(
                ' · orage plus récent (%.0f mm il y a %d j) pas encore productif',
                $latest['millimetres'],
                $latest['daysSince'],
            );
        }

        if ($times['min'] !== $species->flushDelayMinDays) {
            $text .= sprintf(
                ' · délais allongés par le froid (sorties J+%d au lieu de J+%d)',
                $times['min'],
                $species->flushDelayMinDays,
            );
        }

        return $text;
    }

    private static function curve(Species $species, WeatherConditions $weather, int $days): float
    {
        $times = self::timetable($species, $weather);
        $min = $times['min'];
        $peak = $times['peak'];
        $max = $times['max'];
        $persist = $times['persist'];

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
        if ($days <= $max + $persist) {
            return 60.0;
        }

        return max(12.0, 60.0 - ($days - $max - $persist) * 4.0);
    }

    private static function phase(Species $species, WeatherConditions $weather, int $days): string
    {
        $times = self::timetable($species, $weather);

        return match (true) {
            $days < $times['min'] => 'incubating',
            $days < $times['peak'] => 'starting',
            $days <= $times['peak'] + 1 => 'peak',
            $days <= $times['max'] => 'declining',
            $days <= $times['max'] + $times['persist'] => 'lingering',
            default => 'spent',
        };
    }
}
