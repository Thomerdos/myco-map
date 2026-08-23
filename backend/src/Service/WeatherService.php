<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WeatherService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array{
     *   precipitation7d: float,
     *   precipitation14d: float,
     *   avgTemp: float,
     *   humidity: float,
     *   soilMoisture: float,
     *   score: float,
     *   label: string,
     *   reasons: list<string>
     * }
     */
    public function fetchConditions(float $lat, float $lng): array
    {
        $cacheKey = sprintf('weather_%.3f_%.3f', $lat, $lng);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($lat, $lng): array {
            $item->expiresAfter(3600 * 3);

            try {
                $response = $this->httpClient->request(
                    'GET',
                    'https://api.open-meteo.com/v1/forecast',
                    [
                        'query' => [
                            'latitude' => $lat,
                            'longitude' => $lng,
                            'daily' => 'precipitation_sum,temperature_2m_mean,relative_humidity_2m_mean',
                            'hourly' => 'soil_moisture_0_to_1cm',
                            'past_days' => 14,
                            'forecast_days' => 3,
                            'timezone' => 'Europe/Paris',
                        ],
                        'timeout' => 20,
                    ]
                );
                $data = $response->toArray(false);
            } catch (\Throwable) {
                return $this->fallbackConditions();
            }

            $precip = $data['daily']['precipitation_sum'] ?? [];
            $temps = $data['daily']['temperature_2m_mean'] ?? [];
            $humidity = $data['daily']['relative_humidity_2m_mean'] ?? [];
            $soil = $data['hourly']['soil_moisture_0_to_1cm'] ?? [];

            $precip7 = array_sum(array_slice($precip, -10, 7));
            $precip14 = array_sum(array_slice($precip, -17, 14));
            $avgTemp = count($temps) > 0 ? array_sum(array_slice($temps, -7)) / min(7, count($temps)) : 12;
            $avgHumidity = count($humidity) > 0 ? array_sum(array_slice($humidity, -7)) / min(7, count($humidity)) : 70;
            $soilMoisture = count($soil) > 0 ? (float) end($soil) : 0.3;

            return $this->buildConditions($precip7, $precip14, $avgTemp, $avgHumidity, $soilMoisture);
        });
    }

    /**
     * @return array{
     *   precipitation7d: float,
     *   precipitation14d: float,
     *   avgTemp: float,
     *   humidity: float,
     *   soilMoisture: float,
     *   score: float,
     *   label: string,
     *   reasons: list<string>
     * }
     */
    private function buildConditions(float $precip7, float $precip14, float $avgTemp, float $avgHumidity, float $soilMoisture): array
    {
        $reasons = [];
        $score = 50.0;

        if ($precip7 >= 15) {
            $score += 20;
            $reasons[] = sprintf('Pluies récentes favorables (%.0f mm sur 7 j)', $precip7);
        } elseif ($precip7 >= 5) {
            $score += 10;
            $reasons[] = sprintf('Pluie modérée récente (%.0f mm sur 7 j)', $precip7);
        } else {
            $score -= 15;
            $reasons[] = 'Sol probablement sec (peu de pluie récente)';
        }

        if ($precip14 >= 30) {
            $score += 5;
        }

        if ($avgTemp >= 8 && $avgTemp <= 18) {
            $score += 10;
            $reasons[] = sprintf('Température favorable (%.1f °C)', $avgTemp);
        } elseif ($avgTemp > 22) {
            $score -= 10;
            $reasons[] = 'Températures élevées, sol plus sec';
        }

        if ($avgHumidity >= 65) {
            $score += 8;
            $reasons[] = sprintf('Humidité atmosphérique élevée (%.0f %%', $avgHumidity) . ')';
        }

        if ($soilMoisture >= 0.35) {
            $score += 7;
            $reasons[] = 'Humidité du sol favorable';
        }

        $score = max(0, min(100, $score));

        return [
            'precipitation7d' => round($precip7, 1),
            'precipitation14d' => round($precip14, 1),
            'avgTemp' => round($avgTemp, 1),
            'humidity' => round($avgHumidity, 0),
            'soilMoisture' => round($soilMoisture, 3),
            'score' => $score,
            'label' => $score >= 70 ? 'Humide' : ($score >= 45 ? 'Modéré' : 'Sec'),
            'reasons' => $reasons,
        ];
    }

    /** @return array<string, mixed> */
    private function fallbackConditions(): array
    {
        return $this->buildConditions(12, 25, 13, 72, 0.32);
    }

    public function isInHarvestWindow(string $startMd, string $endMd): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $year = (int) $now->format('Y');
        [$startM, $startD] = array_map('intval', explode('-', $startMd));
        [$endM, $endD] = array_map('intval', explode('-', $endMd));

        $start = new \DateTimeImmutable(sprintf('%d-%02d-%02d', $year, $startM, $startD));
        $end = new \DateTimeImmutable(sprintf('%d-%02d-%02d', $year, $endM, $endD));

        if ($start > $end) {
            return $now >= $start || $now <= $end;
        }

        return $now >= $start && $now <= $end;
    }
}
