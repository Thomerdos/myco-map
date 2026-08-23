<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ForestService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array<string, array{type: string, confidence: float}>
     */
    public function fetchForestTypes(float $south, float $west, float $north, float $east): array
    {
        $cacheKey = sprintf('forest_%.2f_%.2f_%.2f_%.2f', $south, $west, $north, $east);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($south, $west, $north, $east): array {
            $item->expiresAfter(86400 * 7);

            $query = sprintf(
                '[out:json][timeout:25];(way["landuse"="forest"](%.4f,%.4f,%.4f,%.4f);way["natural"="wood"](%.4f,%.4f,%.4f,%.4f);relation["landuse"="forest"](%.4f,%.4f,%.4f,%.4f););out geom;',
                $south, $west, $north, $east,
                $south, $west, $north, $east,
                $south, $west, $north, $east,
            );

            try {
                $response = $this->httpClient->request(
                    'POST',
                    'https://overpass-api.de/api/interpreter',
                    ['body' => ['data' => $query], 'timeout' => 30]
                );
                $data = $response->toArray(false);
            } catch (\Throwable) {
                return [];
            }

            $polygons = [];
            foreach ($data['elements'] ?? [] as $element) {
                if (!isset($element['geometry'])) {
                    continue;
                }
                $tags = $element['tags'] ?? [];
                $polygons[] = [
                    'coords' => array_map(static fn (array $n): array => [$n['lat'], $n['lon']], $element['geometry']),
                    'type' => $this->inferForestType($tags),
                ];
            }

            return ['polygons' => $polygons];
        });
    }

    /**
     * @param array<string, mixed> $forestData
     */
    public function classifyPoint(float $lat, float $lng, array $forestData): array
    {
        $polygons = $forestData['polygons'] ?? [];
        foreach ($polygons as $polygon) {
            if ($this->pointInPolygon($lat, $lng, $polygon['coords'])) {
                return ['type' => $polygon['type'], 'confidence' => 0.85];
            }
        }

        return ['type' => 'non_forestier', 'confidence' => 0.4];
    }

    /**
     * @param array<string, string> $tags
     */
    private function inferForestType(array $tags): string
    {
        $leafType = strtolower($tags['leaf_type'] ?? '');
        $wood = strtolower($tags['wood'] ?? '');
        $trees = strtolower($tags['trees'] ?? '');

        if (str_contains($leafType, 'needle') || str_contains($wood, 'conifer') || str_contains($trees, 'conifer')) {
            return 'conifere';
        }
        if (str_contains($leafType, 'broadleaved') || str_contains($wood, 'deciduous')) {
            return 'feuillu';
        }
        if (str_contains($leafType, 'mixed') || str_contains($wood, 'mixed')) {
            return 'mixte';
        }

        return 'forestier';
    }

    /**
     * @param list<array{0: float, 1: float}> $polygon
     */
    private function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $inside = false;
        $count = count($polygon);
        if ($count < 3) {
            return false;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $yi = $polygon[$i][0];
            $xi = $polygon[$i][1];
            $yj = $polygon[$j][0];
            $xj = $polygon[$j][1];

            $intersect = (($yi > $lat) !== ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-9) + $xi);
            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}
