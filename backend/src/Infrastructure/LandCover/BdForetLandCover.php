<?php

declare(strict_types=1);

namespace App\Infrastructure\LandCover;

use App\Domain\Geo\BoundingBox;
use App\Domain\Terrain\CanopyClosure;
use App\Domain\Terrain\ForestCover;
use App\Domain\Terrain\ForestPolygon;
use App\Domain\Terrain\HostTree;
use App\Domain\Terrain\LandCoverSource;
use Psr\Log\LoggerInterface;

/**
 * Forest stands from IGN BD Forêt® V2, which records a photo-interpreted vegetation type for
 * every patch of 0.5 ha and up. That is far more reliable than OpenStreetMap for species
 * composition: most OSM woods around Grenoble carry no `leaf_type` at all and end up as
 * "essence indéterminée", a class that scores identically for every mushroom species.
 *
 * The file is expected as newline-delimited GeoJSON in WGS84 (`ogr2ogr -f GeoJSONSeq`, see
 * `./dev.sh bdforet`), so an entire département streams through without ever being decoded
 * in one piece. Plain GeoJSON is accepted too, for small hand-made extracts.
 *
 * Water always comes from the fallback source: BD Forêt describes vegetation only.
 */
final class BdForetLandCover implements LandCoverSource
{
    /** Polygons per yielded chunk — keeps the rasterizer fed without unbounded memory. */
    private const CHUNK_SIZE = 400;

    private const CODE_FIELDS = ['CODE_TFV', 'code_tfv', 'CODE_TFV_'];

    public function __construct(
        private readonly LandCoverSource $fallback,
        private readonly LoggerInterface $logger,
        private readonly string $datasetPath,
    ) {
    }

    public function unavailableChunks(): int
    {
        return $this->fallback->unavailableChunks();
    }

    public function isAvailable(): bool
    {
        return $this->datasetPath !== '' && is_file($this->datasetPath);
    }

    public function forestPolygons(BoundingBox $bounds): iterable
    {
        if (!$this->isAvailable()) {
            $this->logger->info('BD Forêt absente, repli sur OpenStreetMap', [
                'path' => $this->datasetPath,
            ]);

            return $this->fallback->forestPolygons($bounds);
        }

        $this->logger->info('Couvert forestier depuis BD Forêt V2', [
            'path' => $this->datasetPath,
        ]);

        return $this->streamPolygons($bounds);
    }

    public function waterFeatures(BoundingBox $bounds): iterable
    {
        return $this->fallback->waterFeatures($bounds);
    }

    /** @return iterable<int, list<ForestPolygon>> */
    private function streamPolygons(BoundingBox $bounds): iterable
    {
        $chunk = [];

        foreach ($this->features() as $feature) {
            $code = $this->codeOf($feature);
            $cover = ForestCover::fromBdForetCode($code);
            $geometry = $feature['geometry'] ?? null;
            if (!\is_array($geometry)) {
                continue;
            }

            foreach ($this->polygonsOf($geometry) as $rings) {
                $polygon = $this->toForestPolygon(
                    $cover,
                    HostTree::fromBdForetCode($code),
                    CanopyClosure::fromBdForetCode($code),
                    $rings,
                    $bounds,
                );
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

    /**
     * Newline-delimited features are read one line at a time; a plain FeatureCollection is
     * decoded whole, which is why the streaming format is the documented one.
     *
     * @return iterable<int, array<string, mixed>>
     */
    private function features(): iterable
    {
        $handle = fopen($this->datasetPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('BD Forêt illisible : %s', $this->datasetPath));
        }

        try {
            $first = true;
            while (($line = fgets($handle)) !== false) {
                $line = trim($line, " \t\n\r\0\x0B,");
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                if ($first) {
                    $first = false;
                    if (\is_array($decoded) && isset($decoded['features'])) {
                        // Single-line FeatureCollection: nothing to stream, hand it over as is.
                        yield from array_filter($decoded['features'], 'is_array');

                        return;
                    }
                    if ($decoded === null) {
                        yield from $this->decodeWholeFile();

                        return;
                    }
                }

                if (\is_array($decoded) && isset($decoded['geometry'])) {
                    yield $decoded;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return list<array<string, mixed>> */
    private function decodeWholeFile(): array
    {
        $decoded = json_decode((string) file_get_contents($this->datasetPath), true);
        if (!\is_array($decoded) || !isset($decoded['features']) || !\is_array($decoded['features'])) {
            throw new \RuntimeException(
                sprintf('BD Forêt : GeoJSON inattendu dans %s', $this->datasetPath),
            );
        }

        return array_values(array_filter($decoded['features'], 'is_array'));
    }

    /** @param array<string, mixed> $feature */
    private function codeOf(array $feature): ?string
    {
        $properties = $feature['properties'] ?? [];
        if (!\is_array($properties)) {
            return null;
        }

        foreach (self::CODE_FIELDS as $field) {
            if (isset($properties[$field]) && \is_scalar($properties[$field])) {
                return (string) $properties[$field];
            }
        }

        return null;
    }

    /**
     * Flattens Polygon and MultiPolygon into a list of ring sets, so callers do not have to
     * care which of the two the source used.
     *
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
                // GeoJSON stores longitude first; the rest of the app works in lat/lng.
                if (!\is_array($position) || !isset($position[0], $position[1])) {
                    continue;
                }
                $points[] = [(float) $position[1], (float) $position[0]];
            }

            if (\count($points) >= 3) {
                $converted[] = $points;
            }
        }

        return $converted;
    }

    /**
     * @param list<list<array{0: float, 1: float}>> $rings first ring is the outline, the
     *                                                     remaining ones are clearings
     */
    private function toForestPolygon(
        ForestCover $cover,
        HostTree $host,
        CanopyClosure $canopy,
        array $rings,
        BoundingBox $bounds,
    ): ?ForestPolygon {
        $outer = array_shift($rings);
        if ($outer === null || !$this->touches($outer, $bounds)) {
            return null;
        }

        return new ForestPolygon($cover, [$outer], array_values($rings), $host, $canopy);
    }

    /** @param list<array{0: float, 1: float}> $ring */
    private function touches(array $ring, BoundingBox $bounds): bool
    {
        $south = $north = $ring[0][0];
        $west = $east = $ring[0][1];

        foreach ($ring as [$latitude, $longitude]) {
            $south = min($south, $latitude);
            $north = max($north, $latitude);
            $west = min($west, $longitude);
            $east = max($east, $longitude);
        }

        return $south <= $bounds->north
            && $north >= $bounds->south
            && $west <= $bounds->east
            && $east >= $bounds->west;
    }
}
