<?php

declare(strict_types=1);

namespace App\Infrastructure\LandCover;

use App\Domain\Geo\BoundingBox;
use App\Domain\Terrain\AccessWay;
use App\Domain\Terrain\CanopyClosure;
use App\Domain\Terrain\ForestCover;
use App\Domain\Terrain\ForestPolygon;
use App\Domain\Terrain\HostTree;
use App\Domain\Terrain\LandCoverSource;
use App\Domain\Terrain\WaterFeature;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Land cover from OpenStreetMap through Overpass. The area is split into tiles so a
 * whole mountain range never has to be downloaded in one response, and every tile is
 * cached on disk to keep re-runs offline.
 */
final class OverpassLandCover implements LandCoverSource
{
    private const ENDPOINTS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];

    private const CHUNK_DEGREES = 0.2;
    private const MAX_ATTEMPTS = 4;
    private const COURTESY_DELAY_SECONDS = 2;

    private int $unavailableChunks = 0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
        private readonly string $legacyCacheDirectory,
    ) {
    }

    public function unavailableChunks(): int
    {
        return $this->unavailableChunks;
    }

    public function forestPolygons(BoundingBox $bounds): iterable
    {
        foreach ($this->chunks($bounds) as $index => $chunk) {
            $elements = $this->query(
                sprintf('forest-%d', $index),
                $this->forestQuery($chunk),
            );

            $polygons = [];
            foreach ($elements as $element) {
                $polygon = $this->toForestPolygon($element);
                if ($polygon !== null) {
                    $polygons[] = $polygon;
                }
            }

            yield $polygons;
        }
    }

    public function waterFeatures(BoundingBox $bounds): iterable
    {
        foreach ($this->chunks($bounds) as $index => $chunk) {
            $elements = $this->query(
                sprintf('water-%d', $index),
                $this->waterQuery($chunk),
            );

            $features = [];
            foreach ($elements as $element) {
                $points = $this->pointsFrom($element['geometry'] ?? []);
                if (\count($points) < 2) {
                    continue;
                }
                $features[] = new WaterFeature(
                    $points,
                    ($element['tags']['natural'] ?? null) === 'water',
                );
            }

            yield $features;
        }
    }

    public function accessWays(BoundingBox $bounds, ?BoundingBox $clip = null): iterable
    {
        foreach ($this->chunks($bounds) as $index => $chunk) {
            if ($clip !== null && $chunk->intersect($clip) === null) {
                continue;
            }

            $elements = $this->query(
                sprintf('access-%d', $index),
                $this->accessQuery($chunk),
            );

            $ways = [];
            foreach ($elements as $element) {
                $way = $this->toAccessWay($element);
                if ($way !== null) {
                    $ways[] = $way;
                }
            }

            yield $ways;
        }
    }

    /** @return list<BoundingBox> */
    private function chunks(BoundingBox $bounds): array
    {
        $chunks = [];

        for ($south = $bounds->south; $south < $bounds->north; $south += self::CHUNK_DEGREES) {
            for ($west = $bounds->west; $west < $bounds->east; $west += self::CHUNK_DEGREES) {
                $chunks[] = new BoundingBox(
                    south: $south,
                    west: $west,
                    north: min($bounds->north, $south + self::CHUNK_DEGREES),
                    east: min($bounds->east, $west + self::CHUNK_DEGREES),
                );
            }
        }

        return $chunks;
    }

    /**
     * `landcover=trees` is a widely used alternative to the two canonical forest tags, and
     * `natural=scrub` matters here because mountain scrub often holds the birch and hazel
     * that several target species associate with.
     */
    private function forestQuery(BoundingBox $box): string
    {
        $bbox = $this->bbox($box);

        return <<<QL
            [out:json][timeout:180];
            (
              way["landuse"="forest"]({$bbox});
              way["natural"="wood"]({$bbox});
              way["landcover"="trees"]({$bbox});
              way["natural"="scrub"]({$bbox});
              relation["landuse"="forest"]({$bbox});
              relation["natural"="wood"]({$bbox});
              relation["landcover"="trees"]({$bbox});
              relation["natural"="scrub"]({$bbox});
            );
            out geom;
            QL;
    }

    private function waterQuery(BoundingBox $box): string
    {
        $bbox = $this->bbox($box);

        return <<<QL
            [out:json][timeout:180];
            (
              way["waterway"~"^(river|stream)$"]({$bbox});
              way["natural"="water"]({$bbox});
            );
            out geom;
            QL;
    }

    private function accessQuery(BoundingBox $box): string
    {
        $bbox = $this->bbox($box);

        return <<<QL
            [out:json][timeout:180];
            (
              way["highway"~"^(primary|primary_link|secondary|secondary_link|tertiary|tertiary_link|unclassified|residential|living_street|road|service|track|path|footway|bridleway|cycleway)$"]({$bbox});
              node["amenity"="parking"]({$bbox});
              way["amenity"="parking"]({$bbox});
            );
            out geom;
            QL;
    }

    private function bbox(BoundingBox $box): string
    {
        return sprintf('%.5f,%.5f,%.5f,%.5f', $box->south, $box->west, $box->north, $box->east);
    }

    /**
     * A chunk that cannot be fetched leaves a gap rather than aborting the whole
     * precomputation: successful chunks are cached, so re-running the command fills
     * the gaps without downloading anything twice.
     *
     * @return array<int, array<string, mixed>>
     */
    private function query(string $cacheKey, string $query): array
    {
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($cacheKey, $query): array {
                $item->expiresAfter(null);

                $legacy = $this->legacyElements($cacheKey);
                if ($legacy !== null) {
                    return $legacy;
                }

                return $this->downloadElements($query, $cacheKey);
            });
        } catch (OverpassUnavailable $exception) {
            $this->unavailableChunks++;
            $this->logger->error('Chunk Overpass abandonné', [
                'chunk' => $cacheKey,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * JSON files written before the Symfony FilesystemAdapter pool existed.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function legacyElements(string $cacheKey): ?array
    {
        $paths = [
            sprintf('%s/%s.json', $this->legacyCacheDirectory, $cacheKey),
            sprintf('%s/landcover-rw/%s.json', \dirname($this->legacyCacheDirectory), $cacheKey),
        ];

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            if (\is_array($decoded) && isset($decoded['elements']) && \is_array($decoded['elements'])) {
                return $decoded['elements'];
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function downloadElements(string $query, string $cacheKey): array
    {
        $lastError = 'inconnue';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            foreach (self::ENDPOINTS as $endpoint) {
                try {
                    $response = $this->httpClient->request('POST', $endpoint, [
                        'body' => ['data' => $query],
                        'timeout' => 240,
                    ]);

                    if ($response->getStatusCode() !== 200) {
                        $lastError = 'HTTP ' . $response->getStatusCode();
                        continue;
                    }

                    $content = $response->getContent(false);
                } catch (\Throwable $exception) {
                    $lastError = $exception->getMessage();
                    continue;
                }

                $decoded = json_decode($content, true);
                if (!\is_array($decoded) || !isset($decoded['elements']) || !\is_array($decoded['elements'])) {
                    $lastError = 'réponse Overpass inattendue';
                    continue;
                }

                sleep(self::COURTESY_DELAY_SECONDS);

                return $decoded['elements'];
            }

            $this->logger->warning('Overpass en échec, nouvelle tentative', [
                'chunk' => $cacheKey,
                'attempt' => $attempt,
                'error' => $lastError,
            ]);
            sleep(8 * $attempt);
        }

        throw new OverpassUnavailable($lastError);
    }

    /** @param array<string, mixed> $element */
    private function toForestPolygon(array $element): ?ForestPolygon
    {
        $tags = $element['tags'] ?? [];
        $cover = ForestCover::fromOsmTags($tags);
        $host = HostTree::fromOsmTags($tags);
        $canopy = CanopyClosure::fromOsmTags($tags);

        if (($element['type'] ?? '') === 'relation') {
            $outer = [];
            $inner = [];

            foreach ($element['members'] ?? [] as $member) {
                $points = $this->pointsFrom($member['geometry'] ?? []);
                if (\count($points) < 3) {
                    continue;
                }
                if (($member['role'] ?? 'outer') === 'inner') {
                    $inner[] = $points;
                    continue;
                }
                $outer[] = $points;
            }

            return $outer === [] ? null : new ForestPolygon($cover, $outer, $inner, $host, $canopy);
        }

        $points = $this->pointsFrom($element['geometry'] ?? []);

        return \count($points) < 3 ? null : new ForestPolygon($cover, [$points], hostTree: $host, canopy: $canopy);
    }

    /**
     * @param array<int, array{lat?: float, lon?: float}> $geometry
     * @return list<array{0: float, 1: float}>
     */
    private function pointsFrom(array $geometry): array
    {
        $points = [];

        foreach ($geometry as $node) {
            if (!isset($node['lat'], $node['lon'])) {
                continue;
            }
            $points[] = [(float) $node['lat'], (float) $node['lon']];
        }

        return $points;
    }

    /** @param array<string, mixed> $element */
    private function toAccessWay(array $element): ?AccessWay
    {
        $tags = $element['tags'] ?? [];
        $parkable = OsmWayAccess::isParkable($tags);
        $walkable = OsmWayAccess::isWalkable($tags);
        if (!$parkable && !$walkable) {
            return null;
        }

        $points = $this->pointsFromElement($element);
        if ($points === []) {
            return null;
        }

        $isParking = ($tags['amenity'] ?? null) === 'parking';
        $isArea = $isParking && \count($points) >= 3;

        return new AccessWay($points, $parkable, $walkable, $isArea);
    }

    /**
     * @param array<string, mixed> $element
     * @return list<array{0: float, 1: float}>
     */
    private function pointsFromElement(array $element): array
    {
        if (($element['type'] ?? '') === 'node') {
            if (!isset($element['lat'], $element['lon'])) {
                return [];
            }

            return [[(float) $element['lat'], (float) $element['lon']]];
        }

        return $this->pointsFrom($element['geometry'] ?? []);
    }
}
