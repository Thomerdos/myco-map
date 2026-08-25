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
    /** Phenology multiplier when a flush window meets a dry 3–9 cm profile and little rain since the spell. */
    public const POST_STORM_DRYING_FACTOR = 0.40;

    /**
     * @return array{min: int, peak: int, max: int, persist: int}
     */
    public static function timetable(Species $species, WeatherConditions $weather, ?float $temperature = null): array
    {
        $celsius = $temperature ?? $weather->meanTemperatureCelsius;
        $factor = max(0.82, min(1.40, 1.0 + (13.0 - $celsius) / 28.0));

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
            $best = max($best, self::curve($species, $weather, $spell['daysSince'], self::spellTemperature($spell)));
        }

        if (!$any) {
            return $weather->fortnightRainMillimetres >= 20.0 ? 22.0 : 10.0;
        }

        if (self::flushAbortedByDrying($species, $weather)) {
            $best *= self::POST_STORM_DRYING_FACTOR;
        }

        return $best;
    }

    /**
     * Spell that currently contributes the most to fruiting (not necessarily the last rain).
     *
     * @return array{daysSince: int, millimetres: float, phenology: float, phase: string, rainAfter: float, temperature: ?float}|null
     */
    public static function leadingSpell(Species $species, WeatherConditions $weather): ?array
    {
        $leading = null;

        foreach ($weather->soakingSpells() as $spell) {
            if ($spell['millimetres'] < 15.0) {
                continue;
            }
            $temperature = self::spellTemperature($spell);
            $value = self::curve($species, $weather, $spell['daysSince'], $temperature);
            if ($leading === null || $value > $leading['phenology']) {
                $leading = [
                    'daysSince' => $spell['daysSince'],
                    'millimetres' => $spell['millimetres'],
                    'phenology' => $value,
                    'phase' => self::phase($species, $weather, $spell['daysSince'], $temperature),
                    'rainAfter' => (float) ($spell['rainAfter'] ?? $weather->rainSinceSoakingMillimetres),
                    'temperature' => $temperature,
                ];
            }
        }

        return $leading;
    }

    public static function flushAbortedByDrying(Species $species, WeatherConditions $weather): bool
    {
        $leading = self::leadingSpell($species, $weather);
        if ($leading === null) {
            return false;
        }
        if (!\in_array($leading['phase'], ['starting', 'peak', 'declining', 'lingering'], true)) {
            return false;
        }

        return $weather->driedOutAfterSoaking($leading['rainAfter']);
    }

    public static function label(Species $species, WeatherConditions $weather, \DateTimeImmutable $asOf): string
    {
        if (self::flushAbortedByDrying($species, $weather)) {
            return 'Assèchement trop rapide — pousse compromise';
        }

        $leading = self::leadingSpell($species, $weather);
        if ($leading === null) {
            return $weather->fortnightRainMillimetres < 10.0 ? 'Sec' : 'Sans pluie déclenchante claire';
        }

        $times = self::timetable($species, $weather, $leading['temperature']);
        $name = lcfirst($species->commonName);
        $since = $leading['daysSince'];

        return match ($leading['phase']) {
            'incubating' => sprintf(
                'Incubation %s — sorties vers le %s',
                $name,
                self::frenchDay(self::afterStorm($asOf, $since, $times['min'])),
            ),
            'starting' => sprintf(
                'Pousse %s en cours — pic vers le %s',
                $name,
                self::frenchDay(self::afterStorm($asOf, $since, $times['peak'])),
            ),
            'peak' => sprintf(
                'Pic de pousse — %s (%s)',
                $name,
                self::peakWindow($asOf, $since, $times['peak']),
            ),
            'declining' => sprintf(
                'Fin de poussée %s (jusqu\'au %s)',
                $name,
                self::frenchDay(self::afterStorm($asOf, $since, $times['max'])),
            ),
            'lingering' => sprintf('Encore cueillable — %s', $name),
            default => sprintf('Poussée %s passée', $name),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function decorate(WeatherConditions $weather, Species $species, \DateTimeImmutable $asOf): array
    {
        $leading = self::leadingSpell($species, $weather);
        $payload = $weather->toArray();
        $payload['label'] = self::label($species, $weather, $asOf);
        $payload['flushDaysSince'] = $leading['daysSince'] ?? null;
        $payload['flushPhase'] = $leading['phase'] ?? 'none';
        $payload['flushMillimetres'] = isset($leading) ? round($leading['millimetres'], 1) : null;
        $payload['driedOutAfterSoaking'] = self::flushAbortedByDrying($species, $weather);

        return $payload;
    }

    public static function explain(Species $species, WeatherConditions $weather, \DateTimeImmutable $asOf): string
    {
        $leading = self::leadingSpell($species, $weather);
        $latest = $weather->soakingSpells()[0] ?? null;

        if ($leading === null) {
            return sprintf(
                'Pas d\'épisode marquant récent (%.0f mm sur 15 j). Sans pluie déclenchante, la pousse reste improbable — %.1f °C',
                $weather->fortnightRainMillimetres,
                $weather->meanTemperatureCelsius,
            );
        }

        $times = self::timetable($species, $weather, $leading['temperature']);

        $since = $leading['daysSince'];
        $storm = self::frenchDay(self::afterStorm($asOf, $since, 0));

        $phase = match ($leading['phase']) {
            'incubating' => sprintf(
                'il y a %d j (%s) : incubation, premières sorties vers le %s',
                $since,
                $storm,
                self::frenchDay(self::afterStorm($asOf, $since, $times['min'])),
            ),
            'starting' => sprintf(
                'il y a %d j (%s) : pousse en cours (pic vers le %s)',
                $since,
                $storm,
                self::frenchDay(self::afterStorm($asOf, $since, $times['peak'])),
            ),
            'peak' => sprintf(
                'il y a %d j (%s) : pic de pousse (idéal %s)',
                $since,
                $storm,
                self::peakWindow($asOf, $since, $times['peak']),
            ),
            'declining' => sprintf(
                'il y a %d j (%s) : fin de poussée (fenêtre jusqu\'au %s)',
                $since,
                $storm,
                self::frenchDay(self::afterStorm($asOf, $since, $times['max'])),
            ),
            'lingering' => sprintf(
                'il y a %d j (%s) : carpophores encore cueillables quelques jours',
                $since,
                $storm,
            ),
            default => sprintf(
                'il y a %d j (%s) : cette poussée est derrière nous',
                $since,
                $storm,
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
                ' · orage plus récent (%.0f mm le %s) pas encore productif',
                $latest['millimetres'],
                self::frenchDay(self::afterStorm($asOf, $latest['daysSince'], 0)),
            );
        }

        if ($times['min'] !== $species->flushDelayMinDays) {
            $text .= sprintf(
                ' · délais allongés par le froid (sorties le %s, %d j habituellement)',
                self::frenchDay(self::afterStorm($asOf, $since, $times['min'])),
                $species->flushDelayMinDays,
            );
        }

        if (self::flushAbortedByDrying($species, $weather)) {
            $text .= sprintf(
                ' · l\'épisode a été suivi d\'un assèchement trop rapide (%.0f mm depuis, sol 3–9 cm à %.2f)',
                $leading['rainAfter'],
                $weather->litterSoilMoisture,
            );
        }

        return $text;
    }

    /** Calendar day of a flush delay measured from the soaking spell. */
    private static function afterStorm(\DateTimeImmutable $asOf, int $daysSince, int $delay): \DateTimeImmutable
    {
        return $asOf->modify(sprintf('%+d days', $delay - $daysSince));
    }

    private static function frenchDay(\DateTimeImmutable $date): string
    {
        $months = [1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

        return sprintf('%d %s', (int) $date->format('j'), $months[(int) $date->format('n')]);
    }

    /** Peak phase lasts the peak delay day and the following one. */
    private static function peakWindow(\DateTimeImmutable $asOf, int $daysSince, int $peak): string
    {
        $start = self::afterStorm($asOf, $daysSince, $peak);
        $end = self::afterStorm($asOf, $daysSince, $peak + 1);

        if ($start->format('n') === $end->format('n') && $start->format('Y') === $end->format('Y')) {
            return sprintf('%d–%s', (int) $start->format('j'), self::frenchDay($end));
        }

        return sprintf('%s–%s', self::frenchDay($start), self::frenchDay($end));
    }

    /**
     * @param array{daysSince: int, millimetres: float, rainAfter?: float, temperature?: float} $spell
     */
    private static function spellTemperature(array $spell): ?float
    {
        if (!isset($spell['temperature']) || !is_numeric($spell['temperature'])) {
            return null;
        }

        return (float) $spell['temperature'];
    }

    private static function curve(Species $species, WeatherConditions $weather, int $days, ?float $temperature = null): float
    {
        $times = self::timetable($species, $weather, $temperature);
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

    private static function phase(Species $species, WeatherConditions $weather, int $days, ?float $temperature = null): string
    {
        $times = self::timetable($species, $weather, $temperature);

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
