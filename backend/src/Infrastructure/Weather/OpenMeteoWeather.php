<?php

declare(strict_types=1);

namespace App\Infrastructure\Weather;

use App\Domain\Geo\BoundingBox;
use App\Domain\Geo\Coordinates;
use App\Domain\Weather\WeatherConditions;
use App\Domain\Weather\WeatherField;
use App\Domain\Weather\WeatherLattice;
use App\Domain\Weather\WeatherSource;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Open-Meteo with Météo-France seamless (AROME ~1.5–2.5 km over France) sampled on a
 * 13×13 lattice so Chartreuse / Vercors / Belledonne rain shadows stay visible.
 * Past history + two weeks of forecast so scores can be projected forward day by day.
 */
final class OpenMeteoWeather implements WeatherSource
{
    private const SAMPLES_PER_AXIS = 13;
    private const BATCH_SIZE = 40;
    private const TRIGGER_WINDOW_START = -14;
    private const TRIGGER_WINDOW_END = -5;
    private const RECENT_WINDOW_START = -4;
    private const FORTNIGHT_WINDOW_START = -13;
    private const ACCUMULATION_WINDOW_START = -25;
    private const PRECEDING_WINDOW_START = -29;
    private const PRECEDING_WINDOW_END = -15;
    private const PAST_DAYS = 31;
    /** Enough to project Score to J+14 from "today" in the response. */
    private const FORECAST_DAYS = 16;

    /** @var array<string, WeatherField> */
    private array $fields = [];

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    public function fieldFor(BoundingBox $bounds, ?\DateTimeImmutable $asOf = null): WeatherField
    {
        $timezone = new \DateTimeZone('Europe/Paris');
        $asOf = ($asOf ?? new \DateTimeImmutable('now', $timezone))
            ->setTimezone($timezone)
            ->setTime(12, 0);

        $boundsKey = md5(serialize($bounds->toArray()));
        $hourKey = (new \DateTimeImmutable('now', $timezone))->format('Y-m-d-H');
        $fieldKey = sprintf('weather_field_v5_%s_%s_%s', $boundsKey, $hourKey, $asOf->format('Y-m-d'));

        if (isset($this->fields[$fieldKey])) {
            return $this->fields[$fieldKey];
        }

        $cacheKey = sprintf('weather_raw_v4_%s_%s', $boundsKey, $hourKey);

        /** @var array{samples: list<array{lat: float, lng: float, daily: array<string, mixed>, hourly: array<string, mixed>}>, degraded: bool} $payload */
        $payload = $this->cache->get($cacheKey, function (ItemInterface $item) use ($bounds): array {
            $downloaded = $this->download($bounds);
            // Do not keep a fallback for two hours after a 503: retry soon.
            $item->expiresAfter($downloaded['degraded'] ? 120 : 3600 * 2);

            return $downloaded;
        });

        // Aggregated field per calendar day: layer/location/projection often share the
        // same raw download but need many as-of dates (horizon strip).
        /** @var WeatherField $field */
        $field = $this->cache->get($fieldKey, function (ItemInterface $item) use ($payload, $bounds, $asOf): WeatherField {
            $item->expiresAfter($payload['degraded'] ? 120 : 3600 * 2);

            $samples = [];
            foreach ($payload['samples'] as $sample) {
                $values = $this->aggregate($sample['daily'], $sample['hourly'] ?? [], $asOf);
                $daysRaw = $values['daysSinceSoaking'] ?? -1.0;
                $samples[] = [
                    'point' => new Coordinates($sample['lat'], $sample['lng']),
                    'conditions' => new WeatherConditions(
                        triggerRainMillimetres: $values['triggerRain'],
                        recentRainMillimetres: $values['recentRain'],
                        fortnightRainMillimetres: $values['fortnightRain'],
                        meanTemperatureCelsius: $values['temperature'],
                        relativeHumidityPercent: $values['humidity'],
                        soilMoisture: $values['soilMoisture'],
                        daysSinceSoakingRain: $daysRaw < 0 ? null : (int) $daysRaw,
                        soakingRainMillimetres: $values['soakingRain'] ?? 0.0,
                        accumulatedRainMillimetres: $values['accumulatedRain'] ?? 0.0,
                        precedingDryMillimetres: $values['precedingRain'] ?? 0.0,
                        soakingEvents: $values['soakingEvents'] ?? [],
                        rainSinceSoakingMillimetres: $values['rainSinceSoaking'] ?? 0.0,
                        litterSoilMoisture: $values['litterSoilMoisture'] ?? 0.30,
                    ),
                ];
            }

            $lattice = \count($samples) === self::SAMPLES_PER_AXIS ** 2
                ? new WeatherLattice($bounds, self::SAMPLES_PER_AXIS)
                : null;

            return new WeatherField($samples, $payload['degraded'], $lattice);
        });

        return $this->fields[$fieldKey] = $field;
    }

    /**
     * @return array{
     *   samples: list<array{lat: float, lng: float, daily: array<string, mixed>, hourly: array<string, mixed>}>,
     *   degraded: bool
     * }
     */
    private function download(BoundingBox $bounds): array
    {
        $latitudes = [];
        $longitudes = [];

        for ($row = 0; $row < self::SAMPLES_PER_AXIS; $row++) {
            for ($column = 0; $column < self::SAMPLES_PER_AXIS; $column++) {
                $latitudes[] = round(
                    $bounds->south + ($bounds->north - $bounds->south) * ($row + 0.5) / self::SAMPLES_PER_AXIS,
                    4,
                );
                $longitudes[] = round(
                    $bounds->west + ($bounds->east - $bounds->west) * ($column + 0.5) / self::SAMPLES_PER_AXIS,
                    4,
                );
            }
        }

        try {
            $samples = [];
            $total = \count($latitudes);
            for ($offset = 0; $offset < $total; $offset += self::BATCH_SIZE) {
                $batchLat = \array_slice($latitudes, $offset, self::BATCH_SIZE);
                $batchLng = \array_slice($longitudes, $offset, self::BATCH_SIZE);
                $response = $this->httpClient->request('GET', 'https://api.open-meteo.com/v1/forecast', [
                    'query' => [
                        'latitude' => implode(',', $batchLat),
                        'longitude' => implode(',', $batchLng),
                        'daily' => 'precipitation_sum,temperature_2m_mean',
                        'hourly' => 'relative_humidity_2m,soil_moisture_0_to_1cm,soil_moisture_3_to_9cm',
                        'past_days' => self::PAST_DAYS,
                        'forecast_days' => self::FORECAST_DAYS,
                        'timezone' => 'Europe/Paris',
                        // AROME (+ ARPEGE) over France — orographic rain shadows.
                        'models' => 'meteofrance_seamless',
                    ],
                    'timeout' => 60,
                ]);

                $status = $response->getStatusCode();
                if ($status !== 200) {
                    $this->logger->warning('Open-Meteo indisponible', ['status' => $status, 'offset' => $offset]);

                    return $this->fallback($bounds);
                }

                $payload = $response->toArray(false);
                $locations = isset($payload['daily']) ? [$payload] : $payload;
                if (!\is_array($locations)) {
                    return $this->fallback($bounds);
                }

                foreach ($locations as $index => $location) {
                    if (!\is_array($location) || !isset($location['daily']['precipitation_sum'])) {
                        continue;
                    }
                    $globalIndex = $offset + (int) $index;
                    $samples[] = [
                        'lat' => (float) ($location['latitude'] ?? $latitudes[$globalIndex] ?? $bounds->center()->latitude),
                        'lng' => (float) ($location['longitude'] ?? $longitudes[$globalIndex] ?? $bounds->center()->longitude),
                        'daily' => $location['daily'],
                        'hourly' => \is_array($location['hourly'] ?? null) ? $location['hourly'] : [],
                    ];
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Open-Meteo indisponible', ['error' => $exception->getMessage()]);

            return $this->fallback($bounds);
        }

        return $samples === []
            ? $this->fallback($bounds)
            : ['samples' => $samples, 'degraded' => false];
    }

    /**
     * @param array<string, mixed> $daily
     * @param array<string, mixed> $hourly
     * @return array<string, float>
     */
    private function aggregate(array $daily, array $hourly, \DateTimeImmutable $asOf): array
    {
        $precipitation = array_map('floatval', $daily['precipitation_sum'] ?? []);
        $dates = array_values(array_map('strval', $daily['time'] ?? []));
        $temperatures = array_values(array_filter(
            $daily['temperature_2m_mean'] ?? [],
            'is_numeric',
        ));
        $humidity = array_values(array_filter(
            $hourly['relative_humidity_2m'] ?? [],
            'is_numeric',
        ));
        $soilMoisture = array_values(array_filter(
            $hourly['soil_moisture_0_to_1cm'] ?? [],
            'is_numeric',
        ));
        $litterMoisture = array_values(array_filter(
            $hourly['soil_moisture_3_to_9cm'] ?? [],
            'is_numeric',
        ));
        $hourlyTimes = array_values(array_map('strval', $hourly['time'] ?? []));

        $dayCount = \count($precipitation);
        $asOfKey = $asOf->format('Y-m-d');
        $asOfIndex = array_search($asOfKey, $dates, true);
        if ($asOfIndex === false) {
            // Clamp to the last available day in the series.
            $asOfIndex = max(0, $dayCount - 1);
        }

        $triggerRain = 0.0;
        $recentRain = 0.0;
        $fortnightRain = 0.0;
        $accumulatedRain = 0.0;
        $precedingRain = 0.0;

        for ($day = 0; $day < $dayCount; $day++) {
            $offset = $day - $asOfIndex;
            if ($offset > 0) {
                continue;
            }
            if ($offset >= self::TRIGGER_WINDOW_START && $offset <= self::TRIGGER_WINDOW_END) {
                $triggerRain += $precipitation[$day];
            }
            if ($offset >= self::RECENT_WINDOW_START) {
                $recentRain += $precipitation[$day];
            }
            if ($offset >= self::FORTNIGHT_WINDOW_START) {
                $fortnightRain += $precipitation[$day];
            }
            if ($offset >= self::ACCUMULATION_WINDOW_START) {
                $accumulatedRain += $precipitation[$day];
            }
            if ($offset >= self::PRECEDING_WINDOW_START && $offset <= self::PRECEDING_WINDOW_END) {
                $precedingRain += $precipitation[$day];
            }
        }

        $events = $this->findSoakingEvents($precipitation, $temperatures, (int) $asOfIndex);
        $latest = $events[0] ?? ['daysSince' => null, 'millimetres' => 0.0];

        // Ambient conditions around the target day (daily mean + ~3 days of hourly).
        $tempSlice = [];
        for ($day = max(0, $asOfIndex - 4); $day <= $asOfIndex && $day < \count($temperatures); $day++) {
            $tempSlice[] = $temperatures[$day];
        }

        $humiditySlice = $this->hourlyAround($humidity, $hourlyTimes, $asOfKey, 72);
        $soilSlice = $this->hourlyAround($soilMoisture, $hourlyTimes, $asOfKey, 48);
        $litterSlice = $this->hourlyAround($litterMoisture, $hourlyTimes, $asOfKey, 48);

        $rainSince = 0.0;
        if (($latest['daysSince'] ?? null) !== null) {
            $rainSince = (float) ($latest['rainAfter'] ?? 0.0);
        }

        return [
            'triggerRain' => $triggerRain,
            'recentRain' => $recentRain,
            'fortnightRain' => $fortnightRain,
            'accumulatedRain' => $accumulatedRain,
            'precedingRain' => $precedingRain,
            'temperature' => $this->mean($tempSlice) ?? 12.0,
            'humidity' => $this->mean($humiditySlice) ?? 70.0,
            'soilMoisture' => $this->mean($soilSlice) ?? 0.3,
            'litterSoilMoisture' => $this->mean($litterSlice) ?? 0.3,
            'rainSinceSoaking' => $rainSince,
            'daysSinceSoaking' => $latest['daysSince'] ?? -1.0,
            'soakingRain' => $latest['millimetres'],
            'soakingEvents' => $events,
        ];
    }

    /**
     * @param list<float|int|string> $values
     * @param list<string> $times
     * @return list<float|int|string>
     */
    private function hourlyAround(array $values, array $times, string $asOfKey, int $maxPoints): array
    {
        if ($values === [] || $times === []) {
            return [];
        }

        $end = null;
        for ($i = \count($times) - 1; $i >= 0; $i--) {
            if (str_starts_with($times[$i], $asOfKey)) {
                $end = $i;
                break;
            }
        }
        if ($end === null) {
            $end = \count($values) - 1;
        }

        $start = max(0, $end - $maxPoints + 1);

        return array_slice($values, $start, $end - $start + 1);
    }

    /**
     * Every soaking spell in the series, newest first, so a later storm does not hide
     * a flush already produced by an earlier one.
     *
     * @param list<float> $precipitation
     * @param list<float> $temperatures
     * @return list<array{daysSince: int, millimetres: float, rainAfter: float, temperature: float}>
     */
    private function findSoakingEvents(array $precipitation, array $temperatures, int $asOfIndex): array
    {
        $events = [];
        $cursor = $asOfIndex;

        while ($cursor >= 0 && \count($events) < 6) {
            if ($precipitation[$cursor] < 10.0) {
                --$cursor;
                continue;
            }

            $start = $cursor;
            while ($start > 0 && $precipitation[$start - 1] >= 5.0) {
                --$start;
            }

            $end = $cursor;
            while ($end < $asOfIndex && $precipitation[$end + 1] >= 5.0) {
                ++$end;
            }

            $millimetres = 0.0;
            $tempSlice = [];
            for ($day = $start; $day <= $end; $day++) {
                $millimetres += $precipitation[$day];
                if (isset($temperatures[$day])) {
                    $tempSlice[] = $temperatures[$day];
                }
            }

            $rainAfter = 0.0;
            for ($day = $end + 1; $day <= $asOfIndex; $day++) {
                $rainAfter += $precipitation[$day];
            }

            if ($millimetres >= 15.0) {
                $events[] = [
                    'daysSince' => $asOfIndex - $end,
                    'millimetres' => $millimetres,
                    'rainAfter' => $rainAfter,
                    'temperature' => $this->mean($tempSlice) ?? 12.0,
                ];
            }

            $cursor = $start - 1;
        }

        return $events;
    }

    /** @param list<float|int|string> $values */
    private function mean(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return array_sum(array_map('floatval', $values)) / \count($values);
    }

    /**
     * @return array{
     *   samples: list<array{lat: float, lng: float, daily: array<string, mixed>, hourly: array<string, mixed>}>,
     *   degraded: bool
     * }
     */
    private function fallback(BoundingBox $bounds): array
    {
        $center = $bounds->center();
        $timezone = new \DateTimeZone('Europe/Paris');
        $dates = [];
        $precip = [];
        $temps = [];
        $start = new \DateTimeImmutable('-31 days', $timezone);
        for ($i = 0; $i < 47; $i++) {
            $day = $start->modify(sprintf('+%d days', $i));
            $dates[] = $day->format('Y-m-d');
            $precip[] = $i >= 20 && $i <= 22 ? 12.0 : 1.5;
            $temps[] = 12.5;
        }

        return [
            'samples' => [[
                'lat' => $center->latitude,
                'lng' => $center->longitude,
                'daily' => [
                    'time' => $dates,
                    'precipitation_sum' => $precip,
                    'temperature_2m_mean' => $temps,
                ],
                'hourly' => [
                    'time' => [],
                    'relative_humidity_2m' => [],
                    'soil_moisture_0_to_1cm' => [],
                    'soil_moisture_3_to_9cm' => [],
                ],
            ]],
            'degraded' => true,
        ];
    }
}
