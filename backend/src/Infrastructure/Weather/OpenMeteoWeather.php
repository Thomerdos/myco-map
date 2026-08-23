<?php

declare(strict_types=1);

namespace App\Infrastructure\Weather;

use App\Domain\Geo\BoundingBox;
use App\Domain\Geo\Coordinates;
use App\Domain\Weather\WeatherConditions;
use App\Domain\Weather\WeatherField;
use App\Domain\Weather\WeatherSource;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Open-Meteo sampled on a small lattice across the area, so the rain gradient between
 * the Grésivaudan valley and the ridges is preserved instead of averaged away.
 */
final readonly class OpenMeteoWeather implements WeatherSource
{
    private const SAMPLES_PER_AXIS = 3;
    private const TRIGGER_WINDOW_START = -14;
    private const TRIGGER_WINDOW_END = -5;
    private const RECENT_WINDOW_START = -4;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    public function fieldFor(BoundingBox $bounds): WeatherField
    {
        $cacheKey = sprintf(
            'weather_%s_%s',
            md5(serialize($bounds->toArray())),
            (new \DateTimeImmutable())->format('Y-m-d-H'),
        );

        /** @var array{samples: list<array{lat: float, lng: float, values: array<string, float>}>, degraded: bool} $payload */
        $payload = $this->cache->get($cacheKey, function (ItemInterface $item) use ($bounds): array {
            $item->expiresAfter(3600 * 2);

            return $this->download($bounds);
        });

        $samples = [];
        foreach ($payload['samples'] as $sample) {
            $values = $sample['values'];
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
                ),
            ];
        }

        return new WeatherField($samples, $payload['degraded']);
    }

    /** @return array{samples: list<array{lat: float, lng: float, values: array<string, float>}>, degraded: bool} */
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
            $response = $this->httpClient->request('GET', 'https://api.open-meteo.com/v1/forecast', [
                'query' => [
                    'latitude' => implode(',', $latitudes),
                    'longitude' => implode(',', $longitudes),
                    'daily' => 'precipitation_sum,temperature_2m_mean',
                    'hourly' => 'relative_humidity_2m,soil_moisture_0_to_1cm',
                    'past_days' => 15,
                    'forecast_days' => 1,
                    'timezone' => 'Europe/Paris',
                ],
                'timeout' => 45,
            ]);

            $payload = $response->toArray(false);
        } catch (\Throwable $exception) {
            $this->logger->warning('Open-Meteo indisponible', ['error' => $exception->getMessage()]);

            return $this->fallback($bounds);
        }

        $locations = isset($payload['daily']) ? [$payload] : $payload;
        if (!\is_array($locations)) {
            return $this->fallback($bounds);
        }

        $samples = [];
        foreach ($locations as $index => $location) {
            if (!\is_array($location) || !isset($location['daily']['precipitation_sum'])) {
                continue;
            }

            $samples[] = [
                'lat' => (float) ($location['latitude'] ?? $latitudes[$index] ?? $bounds->center()->latitude),
                'lng' => (float) ($location['longitude'] ?? $longitudes[$index] ?? $bounds->center()->longitude),
                'values' => $this->aggregate($location),
            ];
        }

        return $samples === []
            ? $this->fallback($bounds)
            : ['samples' => $samples, 'degraded' => false];
    }

    /**
     * @param array<string, mixed> $location
     * @return array<string, float>
     */
    private function aggregate(array $location): array
    {
        $precipitation = array_map('floatval', $location['daily']['precipitation_sum'] ?? []);
        $dates = array_values(array_map('strval', $location['daily']['time'] ?? []));
        $temperatures = array_values(array_filter(
            $location['daily']['temperature_2m_mean'] ?? [],
            'is_numeric',
        ));
        $humidity = array_values(array_filter(
            $location['hourly']['relative_humidity_2m'] ?? [],
            'is_numeric',
        ));
        $soilMoisture = array_values(array_filter(
            $location['hourly']['soil_moisture_0_to_1cm'] ?? [],
            'is_numeric',
        ));

        $dayCount = \count($precipitation);
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
        $todayIndex = array_search($today, $dates, true);
        if ($todayIndex === false) {
            $todayIndex = max(0, $dayCount - 2);
        }

        $triggerRain = 0.0;
        $recentRain = 0.0;

        for ($day = 0; $day < $dayCount; $day++) {
            $offset = $day - $todayIndex;
            if ($offset >= self::TRIGGER_WINDOW_START && $offset <= self::TRIGGER_WINDOW_END) {
                $triggerRain += $precipitation[$day];
            }
            if ($offset >= self::RECENT_WINDOW_START && $offset <= 0) {
                $recentRain += $precipitation[$day];
            }
        }

        $soaking = $this->findSoakingEvent($precipitation, (int) $todayIndex);

        return [
            'triggerRain' => $triggerRain,
            'recentRain' => $recentRain,
            'fortnightRain' => array_sum($precipitation),
            'temperature' => $this->mean(\array_slice($temperatures, -10)) ?? 12.0,
            'humidity' => $this->mean(\array_slice($humidity, -72)) ?? 70.0,
            'soilMoisture' => $this->mean(\array_slice($soilMoisture, -48)) ?? 0.3,
            'daysSinceSoaking' => $soaking['daysSince'] ?? -1.0,
            'soakingRain' => $soaking['millimetres'],
        ];
    }

    /**
     * Last marked rainy spell: a day ≥ 10 mm expanded to neighbouring wet days.
     * Fruiting clocks start at the *end* of that spell, not at its first shower.
     *
     * @param list<float> $precipitation
     * @return array{daysSince: ?float, millimetres: float}
     */
    private function findSoakingEvent(array $precipitation, int $todayIndex): array
    {
        for ($cursor = $todayIndex; $cursor >= 0; $cursor--) {
            if ($precipitation[$cursor] < 10.0) {
                continue;
            }

            $start = $cursor;
            while ($start > 0 && $precipitation[$start - 1] >= 5.0) {
                --$start;
            }

            $end = $cursor;
            while ($end < $todayIndex && $precipitation[$end + 1] >= 5.0) {
                ++$end;
            }

            $millimetres = 0.0;
            for ($day = $start; $day <= $end; $day++) {
                $millimetres += $precipitation[$day];
            }

            if ($millimetres < 15.0) {
                continue;
            }

            return [
                'daysSince' => (float) ($todayIndex - $end),
                'millimetres' => $millimetres,
            ];
        }

        return ['daysSince' => null, 'millimetres' => 0.0];
    }

    /** @param list<float|int|string> $values */
    private function mean(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return array_sum(array_map('floatval', $values)) / \count($values);
    }

    /** @return array{samples: list<array{lat: float, lng: float, values: array<string, float>}>, degraded: bool} */
    private function fallback(BoundingBox $bounds): array
    {
        $center = $bounds->center();

        return [
            'samples' => [[
                'lat' => $center->latitude,
                'lng' => $center->longitude,
                'values' => [
                    'triggerRain' => 18.0,
                    'recentRain' => 4.0,
                    'fortnightRain' => 28.0,
                    'temperature' => 12.5,
                    'humidity' => 72.0,
                    'soilMoisture' => 0.3,
                ],
            ]],
            'degraded' => true,
        ];
    }
}
