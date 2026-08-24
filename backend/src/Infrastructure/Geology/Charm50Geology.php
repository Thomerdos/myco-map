<?php

declare(strict_types=1);

namespace App\Infrastructure\Geology;

use App\Domain\Geo\BoundingBox;
use App\Domain\Terrain\GeologyPolygon;
use App\Domain\Terrain\GeologySource;
use App\Domain\Terrain\Substrate;
use Psr\Log\LoggerInterface;

/**
 * BRGM Charm-50 formations converted to newline-delimited GeoJSON (WGS84) with a
 * pre-classified {@see Substrate} code. Absent file → empty stream (Unknown everywhere).
 */
final class Charm50Geology implements GeologySource
{
    private const CHUNK_SIZE = 400;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $datasetPath,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->datasetPath !== '' && is_file($this->datasetPath);
    }

    public function geologyPolygons(BoundingBox $bounds): iterable
    {
        if (!$this->isAvailable()) {
            $this->logger->info('Géologie BRGM absente, substrat indéterminé partout', [
                'path' => $this->datasetPath,
            ]);

            return [];
        }

        $this->logger->info('Substrat depuis BRGM Charm-50', ['path' => $this->datasetPath]);

        return $this->streamPolygons($bounds);
    }

    /** @return iterable<int, list<GeologyPolygon>> */
    private function streamPolygons(BoundingBox $bounds): iterable
    {
        $chunk = [];

        foreach ($this->features() as $feature) {
            $geometry = $feature['geometry'] ?? null;
            if (!\is_array($geometry)) {
                continue;
            }

            $props = $feature['properties'] ?? [];
            $substrate = Substrate::tryFrom((int) ($props['substrate'] ?? -1)) ?? Substrate::Unknown;
            if ($substrate === Substrate::Unknown && isset($props['descr'])) {
                $substrate = Substrate::fromDescription((string) $props['descr']);
            }

            foreach ($this->polygonsOf($geometry) as $rings) {
                $polygon = $this->toGeologyPolygon($substrate, $rings, $bounds);
                if ($polygon === null) {
                    continue;
                }

                $chunk[] = $polygon;
                if (\count($chunk) >= self::CHUNK_SIZE) {
                    yield $chunk;
                    $chunk = [];
                }
            }
        }

        if ($chunk !== []) {
            yield $chunk;
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function features(): iterable
    {
        $handle = fopen($this->datasetPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Géologie illisible : %s', $this->datasetPath));
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (\is_array($decoded) && isset($decoded['geometry'])) {
                    yield $decoded;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $geometry
     * @return iterable<int, list<list<array{0: float, 1: float}>>>
     */
    private function polygonsOf(array $geometry): iterable
    {
        $coordinates = $geometry['coordinates'] ?? null;
        if (!\is_array($coordinates)) {
            return;
        }

        $type = (string) ($geometry['type'] ?? '');
        if ($type === 'Polygon') {
            $rings = $this->ringsOf($coordinates);
            if ($rings !== []) {
                yield $rings;
            }

            return;
        }

        if ($type !== 'MultiPolygon') {
            return;
        }

        foreach ($coordinates as $polygon) {
            if (!\is_array($polygon)) {
                continue;
            }
            $rings = $this->ringsOf($polygon);
            if ($rings !== []) {
                yield $rings;
            }
        }
    }

    /**
     * @param array<int, mixed> $rings
     * @return list<list<array{0: float, 1: float}>>
     */
    private function ringsOf(array $rings): array
    {
        $converted = [];
        foreach ($rings as $ring) {
            if (!\is_array($ring)) {
                continue;
            }
            $points = [];
            foreach ($ring as $position) {
                if (!\is_array($position) || !isset($position[0], $position[1])) {
                    continue;
                }
                // GeoJSON is lon/lat; the rasterizer expects lat/lng.
                $points[] = [(float) $position[1], (float) $position[0]];
            }
            if (\count($points) >= 4) {
                $converted[] = $points;
            }
        }

        return $converted;
    }

    /**
     * @param list<list<array{0: float, 1: float}>> $rings
     */
    private function toGeologyPolygon(Substrate $substrate, array $rings, BoundingBox $bounds): ?GeologyPolygon
    {
        if ($rings === [] || !$this->ringsMeetBounds($rings, $bounds)) {
            return null;
        }

        return new GeologyPolygon(
            $substrate,
            [$rings[0]],
            array_slice($rings, 1),
        );
    }

    /** @param list<list<array{0: float, 1: float}>> $rings */
    private function ringsMeetBounds(array $rings, BoundingBox $bounds): bool
    {
        $minLat = $minLng = INF;
        $maxLat = $maxLng = -INF;
        foreach ($rings as $ring) {
            foreach ($ring as [$lat, $lng]) {
                $minLat = min($minLat, $lat);
                $maxLat = max($maxLat, $lat);
                $minLng = min($minLng, $lng);
                $maxLng = max($maxLng, $lng);
            }
        }

        return $minLat <= $bounds->north && $maxLat >= $bounds->south
            && $minLng <= $bounds->east && $maxLng >= $bounds->west;
    }
}
