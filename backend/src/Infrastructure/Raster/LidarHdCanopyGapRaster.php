<?php

declare(strict_types=1);

namespace App\Infrastructure\Raster;

use App\Domain\Geo\Grid;
use App\Domain\Terrain\CanopyGapSource;
use Psr\Log\LoggerInterface;

/**
 * Reads canopy-gap.bin (uint8 0–100 %, 255 = unknown) written next to the CHM by convert_lidar_chm.
 */
final class LidarHdCanopyGapRaster implements CanopyGapSource
{
    private const UNKNOWN = 255;

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

    public function sample(Grid $grid): \SplFixedArray
    {
        $total = $grid->cellCount();
        /** @var \SplFixedArray<int> $out */
        $out = new \SplFixedArray($total);
        for ($i = 0; $i < $total; $i++) {
            $out[$i] = -1;
        }

        if (!$this->isAvailable()) {
            $this->logger->info('Trous de canopée LIDAR absents', ['path' => $this->datasetPath]);

            return $out;
        }

        $meta = $this->readSidecar();
        $bytes = file_get_contents($this->binPath());
        if ($bytes === false || $meta === null) {
            return $out;
        }

        $columns = (int) $meta['columns'];
        $rows = (int) $meta['rows'];
        $expected = $columns * $rows;
        if (strlen($bytes) < $expected) {
            $this->logger->warning('Grille canopy-gap tronquée', ['bytes' => strlen($bytes), 'expected' => $expected]);

            return $out;
        }

        $this->logger->info('Fraction de clairières depuis LIDAR HD', [
            'cells' => $expected,
            'path' => $this->binPath(),
        ]);

        $sameLattice = $columns === $grid->columns
            && $rows === $grid->rows
            && abs((float) $meta['south'] - $grid->bounds->south) < 1e-6
            && abs((float) $meta['west'] - $grid->bounds->west) < 1e-6;

        if ($sameLattice) {
            for ($i = 0; $i < $total; $i++) {
                $out[$i] = $this->decodeByte(ord($bytes[$i]));
            }

            return $out;
        }

        for ($row = 0; $row < $grid->rows; $row++) {
            $rowOffset = $row * $grid->columns;
            for ($column = 0; $column < $grid->columns; $column++) {
                $point = $grid->coordinatesAt($column, $row);
                $srcColumn = (int) floor(($point->longitude - (float) $meta['west']) / (float) $meta['longitudeStep']);
                $srcRow = (int) floor(($point->latitude - (float) $meta['south']) / (float) $meta['latitudeStep']);
                if ($srcColumn < 0 || $srcRow < 0 || $srcColumn >= $columns || $srcRow >= $rows) {
                    continue;
                }
                $out[$rowOffset + $column] = $this->decodeByte(ord($bytes[$srcRow * $columns + $srcColumn]));
            }
        }

        return $out;
    }

    private function decodeByte(int $value): int
    {
        if ($value === self::UNKNOWN || $value > 100) {
            return -1;
        }

        return $value;
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

    /** @return array<string, mixed>|null */
    private function readSidecar(): ?array
    {
        $raw = @file_get_contents($this->sidecarPath());
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : null;
    }
}
