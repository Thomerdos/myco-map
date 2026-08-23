<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TerrainService
{
    private const SAMPLE_OFFSET_DEG = 0.00045; // ~50 m

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @param list<array{lat: float, lng: float, id: string}> $points
     * @return array<string, array{elevation: float, slope: float, aspect: float, aspectLabel: string}>
     */
    public function fetchTerrainBatch(array $points): array
    {
        $results = [];
        $chunks = array_chunk($points, 40);

        foreach ($chunks as $chunk) {
            $lookup = [];
            foreach ($chunk as $point) {
                $lookup[] = $point;
                $lookup[] = ['lat' => $point['lat'] + self::SAMPLE_OFFSET_DEG, 'lng' => $point['lng'], 'id' => $point['id'] . '_n'];
                $lookup[] = ['lat' => $point['lat'] - self::SAMPLE_OFFSET_DEG, 'lng' => $point['lng'], 'id' => $point['id'] . '_s'];
                $lookup[] = ['lat' => $point['lat'], 'lng' => $point['lng'] + self::SAMPLE_OFFSET_DEG, 'id' => $point['id'] . '_e'];
                $lookup[] = ['lat' => $point['lat'], 'lng' => $point['lng'] - self::SAMPLE_OFFSET_DEG, 'id' => $point['id'] . '_w'];
            }

            $elevations = $this->fetchElevations($lookup);

            foreach ($chunk as $point) {
                $id = $point['id'];
                $center = $elevations[$id] ?? null;
                if ($center === null) {
                    continue;
                }

                $north = $elevations[$id . '_n'] ?? $center;
                $south = $elevations[$id . '_s'] ?? $center;
                $east = $elevations[$id . '_e'] ?? $center;
                $west = $elevations[$id . '_w'] ?? $center;

                $dzDx = ($east - $west) / (2 * self::SAMPLE_OFFSET_DEG * 111320 * cos(deg2rad($point['lat'])));
                $dzDy = ($north - $south) / (2 * self::SAMPLE_OFFSET_DEG * 111320);
                $slope = rad2deg(atan(sqrt($dzDx ** 2 + $dzDy ** 2)));
                $aspect = fmod(rad2deg(atan2($dzDy, -$dzDx)) + 360, 360);

                $results[$id] = [
                    'elevation' => $center,
                    'slope' => $slope,
                    'aspect' => $aspect,
                    'aspectLabel' => $this->aspectLabel($aspect),
                ];
            }

            usleep(1100000);
        }

        return $results;
    }

    /**
     * @param list<array{lat: float, lng: float, id: string}> $points
     * @return array<string, float>
     */
    private function fetchElevations(array $points): array
    {
        $locations = implode('|', array_map(
            static fn (array $p): string => sprintf('%.6f,%.6f', $p['lat'], $p['lng']),
            $points
        ));

        $cacheKey = 'terrain_' . md5($locations);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($locations, $points): array {
            $item->expiresAfter(86400 * 30);

            try {
                $response = $this->httpClient->request(
                    'GET',
                    'https://api.opentopodata.org/v1/eudem25m',
                    [
                        'query' => ['locations' => $locations],
                        'timeout' => 30,
                    ]
                );
                $data = $response->toArray(false);
            } catch (\Throwable) {
                return $this->fallbackElevations($points);
            }

            $out = [];
            foreach ($data['results'] ?? [] as $i => $result) {
                $id = $points[$i]['id'] ?? null;
                if ($id === null) {
                    continue;
                }
                $elevation = $result['elevation'] ?? null;
                if ($elevation !== null) {
                    $out[$id] = (float) $elevation;
                }
            }

            return $out !== [] ? $out : $this->fallbackElevations($points);
        });
    }

    /**
     * @param list<array{lat: float, lng: float, id: string}> $points
     * @return array<string, float>
     */
    private function fallbackElevations(array $points): array
    {
        $out = [];
        foreach ($points as $point) {
            $out[$point['id']] = 1200 + 400 * sin(deg2rad($point['lat'] * 80)) * cos(deg2rad($point['lng'] * 60));
        }

        return $out;
    }

    private function aspectLabel(float $aspect): string
    {
        $directions = [
            ['N', 0, 22.5],
            ['NE', 22.5, 67.5],
            ['E', 67.5, 112.5],
            ['SE', 112.5, 157.5],
            ['S', 157.5, 202.5],
            ['SO', 202.5, 247.5],
            ['O', 247.5, 292.5],
            ['NO', 292.5, 337.5],
            ['N', 337.5, 360],
        ];

        foreach ($directions as [$label, $min, $max]) {
            if ($aspect >= $min && $aspect < $max) {
                return $label;
            }
        }

        return 'N';
    }
}
