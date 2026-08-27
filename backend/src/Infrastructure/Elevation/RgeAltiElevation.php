<?php

declare(strict_types=1);

namespace App\Infrastructure\Elevation;

use App\Domain\Geo\BoundingBox;
use App\Domain\Geo\Coordinates;
use App\Domain\Terrain\ElevationSampler;
use Psr\Log\LoggerInterface;

/**
 * Reads the compact DEM written by `./dev.sh rgealti` (int16 decimetres, south→north).
 * Missing mosaic → not available; {@see CompositeElevationSampler} falls back to Terrarium.
 */
final class RgeAltiElevation implements ElevationSampler
{
    private const NODATA = -32768;

    /** @var array{columns: int, rows: int, south: float, west: float, latitudeStep: float, longitudeStep: float}|null */
    private ?array $meta = null;

    private ?string $bytes = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $datasetPath,
    ) {
    }

    public function isAvailable(): bool
    {
        $json = $this->sidecarPath();
        $bin = $this->binPath();

        return $json !== '' && $bin !== '' && is_file($json) && is_file($bin);
    }

    public function prepare(BoundingBox $bounds): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $this->load();

        return 1;
    }

    public function elevationAt(Coordinates $point): ?float
    {
        return $this->elevationAtLatLng($point->latitude, $point->longitude);
    }

    public function elevationAtLatLng(float $latitude, float $longitude): ?float
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $this->load();
        if ($this->meta === null || $this->bytes === null) {
            return null;
        }

        $column = (int) floor(($longitude - $this->meta['west']) / $this->meta['longitudeStep']);
        $row = (int) floor(($latitude - $this->meta['south']) / $this->meta['latitudeStep']);
        if ($column < 0 || $row < 0 || $column >= $this->meta['columns'] || $row >= $this->meta['rows']) {
            return null;
        }

        $offset = ($row * $this->meta['columns'] + $column) * 2;
        if ($offset + 1 >= strlen($this->bytes)) {
            return null;
        }

        $lo = ord($this->bytes[$offset]);
        $hi = ord($this->bytes[$offset + 1]);
        $dm = $lo | ($hi << 8);
        if ($dm >= 0x8000) {
            $dm -= 0x10000;
        }
        if ($dm === self::NODATA) {
            return null;
        }

        return $dm / 10.0;
    }

    private function load(): void
    {
        if ($this->meta !== null && $this->bytes !== null) {
            return;
        }

        $raw = @file_get_contents($this->sidecarPath());
        $bin = @file_get_contents($this->binPath());
        if ($raw === false || $bin === false) {
            $this->logger->warning('Mosaïque RGE ALTI illisible', ['path' => $this->datasetPath]);

            return;
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            return;
        }

        $this->meta = [
            'columns' => (int) $decoded['columns'],
            'rows' => (int) $decoded['rows'],
            'south' => (float) $decoded['south'],
            'west' => (float) $decoded['west'],
            'latitudeStep' => (float) $decoded['latitudeStep'],
            'longitudeStep' => (float) $decoded['longitudeStep'],
        ];
        $this->bytes = $bin;
    }

    private function sidecarPath(): string
    {
        if (str_ends_with($this->datasetPath, '.json')) {
            return $this->datasetPath;
        }
        if (str_ends_with($this->datasetPath, '.bin')) {
            return preg_replace('/\.bin$/', '.json', $this->datasetPath) ?? '';
        }

        return $this->datasetPath.'.json';
    }

    private function binPath(): string
    {
        if (str_ends_with($this->datasetPath, '.bin')) {
            return $this->datasetPath;
        }
        if (str_ends_with($this->datasetPath, '.json')) {
            return preg_replace('/\.json$/', '.bin', $this->datasetPath) ?? '';
        }

        return $this->datasetPath.'.bin';
    }
}
