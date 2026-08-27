<?php

declare(strict_types=1);

namespace App\Domain\Weather;

use App\Domain\Geo\BoundingBox;
use App\Domain\Geo\Coordinates;

/**
 * Regular sample grid matching Open-Meteo download order (row-major, south→north,
 * west→east). Lets {@see WeatherField::at()} resolve a cell in O(1) instead of
 * inverse-distance weighting over every sample.
 */
final readonly class WeatherLattice
{
    public function __construct(
        public BoundingBox $bounds,
        public int $samplesPerAxis,
    ) {
        if ($samplesPerAxis < 1) {
            throw new \InvalidArgumentException('La grille météo requiert au moins un échantillon par axe.');
        }
    }

    /**
     * Nearest sample index (row-major).
     */
    public function nearestIndex(Coordinates $point): int
    {
        [$row, $column] = $this->nearestRowColumn($point);

        return $row * $this->samplesPerAxis + $column;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function nearestRowColumn(Coordinates $point): array
    {
        $n = $this->samplesPerAxis;
        $row = (int) floor($this->normalizedRow($point->latitude));
        $column = (int) floor($this->normalizedColumn($point->longitude));

        return [
            max(0, min($n - 1, $row)),
            max(0, min($n - 1, $column)),
        ];
    }

    /**
     * Continuous sample-center coordinates and the four surrounding indices for
     * bilinear blending. Each corner is [index, weight].
     *
     * @return list<array{0: int, 1: float}>
     */
    public function bilinearCorners(Coordinates $point): array
    {
        $n = $this->samplesPerAxis;
        // Sample centres sit at (i+0.5)/n — invert that so integer i is the cell.
        $y = $this->normalizedRow($point->latitude) - 0.5;
        $x = $this->normalizedColumn($point->longitude) - 0.5;

        $row0 = (int) floor($y);
        $col0 = (int) floor($x);
        $ty = $y - $row0;
        $tx = $x - $col0;

        $row0 = max(0, min($n - 1, $row0));
        $col0 = max(0, min($n - 1, $col0));
        $row1 = min($n - 1, $row0 + 1);
        $col1 = min($n - 1, $col0 + 1);

        if ($row0 === $row1) {
            $ty = 0.0;
        }
        if ($col0 === $col1) {
            $tx = 0.0;
        }

        $w00 = (1.0 - $tx) * (1.0 - $ty);
        $w10 = $tx * (1.0 - $ty);
        $w01 = (1.0 - $tx) * $ty;
        $w11 = $tx * $ty;

        $corners = [
            [$row0 * $n + $col0, $w00],
            [$row0 * $n + $col1, $w10],
            [$row1 * $n + $col0, $w01],
            [$row1 * $n + $col1, $w11],
        ];

        // Collapse duplicate indices on the lattice edge.
        $merged = [];
        foreach ($corners as [$index, $weight]) {
            if ($weight <= 0.0) {
                continue;
            }
            $merged[$index] = ($merged[$index] ?? 0.0) + $weight;
        }

        $out = [];
        foreach ($merged as $index => $weight) {
            $out[] = [(int) $index, $weight];
        }

        return $out;
    }

    private function normalizedRow(float $latitude): float
    {
        $span = $this->bounds->north - $this->bounds->south;
        if ($span <= 0.0) {
            return 0.0;
        }

        return ($latitude - $this->bounds->south) / $span * $this->samplesPerAxis;
    }

    private function normalizedColumn(float $longitude): float
    {
        $span = $this->bounds->east - $this->bounds->west;
        if ($span <= 0.0) {
            return 0.0;
        }

        return ($longitude - $this->bounds->west) / $span * $this->samplesPerAxis;
    }
}
