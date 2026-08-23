<?php

declare(strict_types=1);

namespace App\Infrastructure\Raster;

use App\Domain\Geo\Grid;

/**
 * Scanline rasterisation of geographic rings and polylines onto a grid-aligned raster.
 */
final readonly class PolygonRasterizer
{
    /**
     * @param \SplFixedArray<int> $raster
     * @param list<array{0: float, 1: float}> $ring lat/lng pairs
     */
    public function fillRing(\SplFixedArray $raster, Grid $grid, array $ring, int $value): void
    {
        $vertexCount = \count($ring);
        if ($vertexCount < 3) {
            return;
        }

        $minLatitude = $maxLatitude = $ring[0][0];
        foreach ($ring as [$latitude, $longitude]) {
            $minLatitude = min($minLatitude, $latitude);
            $maxLatitude = max($maxLatitude, $latitude);
        }

        $firstRow = max(0, $grid->rowFor($minLatitude));
        $lastRow = min($grid->rows - 1, $grid->rowFor($maxLatitude));

        for ($row = $firstRow; $row <= $lastRow; $row++) {
            $latitude = $grid->latitudeAt($row);
            $crossings = [];

            for ($i = 0, $j = $vertexCount - 1; $i < $vertexCount; $j = $i++) {
                $latitudeI = $ring[$i][0];
                $latitudeJ = $ring[$j][0];

                if (($latitudeI <= $latitude && $latitudeJ > $latitude)
                    || ($latitudeJ <= $latitude && $latitudeI > $latitude)) {
                    $ratio = ($latitude - $latitudeI) / ($latitudeJ - $latitudeI);
                    $crossings[] = $ring[$i][1] + $ratio * ($ring[$j][1] - $ring[$i][1]);
                }
            }

            $crossingCount = \count($crossings);
            if ($crossingCount < 2) {
                continue;
            }

            sort($crossings);
            $rowOffset = $row * $grid->columns;

            for ($k = 0; $k + 1 < $crossingCount; $k += 2) {
                $from = max(0, $grid->columnFor($crossings[$k]));
                $to = min($grid->columns - 1, $grid->columnFor($crossings[$k + 1]));
                for ($column = $from; $column <= $to; $column++) {
                    $raster[$rowOffset + $column] = $value;
                }
            }
        }
    }

    /**
     * @param \SplFixedArray<int> $raster
     * @param list<array{0: float, 1: float}> $points
     */
    public function stampPolyline(\SplFixedArray $raster, Grid $grid, array $points, int $value): void
    {
        $pointCount = \count($points);

        for ($i = 0; $i + 1 < $pointCount; $i++) {
            $fromColumn = $grid->columnFor($points[$i][1]);
            $fromRow = $grid->rowFor($points[$i][0]);
            $toColumn = $grid->columnFor($points[$i + 1][1]);
            $toRow = $grid->rowFor($points[$i + 1][0]);

            $steps = max(abs($toColumn - $fromColumn), abs($toRow - $fromRow));
            if ($steps === 0) {
                $this->plot($raster, $grid, $fromColumn, $fromRow, $value);
                continue;
            }

            for ($step = 0; $step <= $steps; $step++) {
                $ratio = $step / $steps;
                $this->plot(
                    $raster,
                    $grid,
                    (int) round($fromColumn + ($toColumn - $fromColumn) * $ratio),
                    (int) round($fromRow + ($toRow - $fromRow) * $ratio),
                    $value,
                );
            }
        }
    }

    /** @param \SplFixedArray<int> $raster */
    private function plot(\SplFixedArray $raster, Grid $grid, int $column, int $row, int $value): void
    {
        if ($column < 0 || $row < 0 || $column >= $grid->columns || $row >= $grid->rows) {
            return;
        }

        $raster[$row * $grid->columns + $column] = $value;
    }
}
