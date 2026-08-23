<?php

declare(strict_types=1);

namespace App\Infrastructure\Raster;

use App\Domain\Geo\Grid;

/**
 * Slope and aspect via Horn's third-order finite difference, plus a Laplacian
 * curvature used as a concavity proxy.
 */
final readonly class TerrainDerivatives
{
    /**
     * @param \SplFixedArray<float> $elevation
     * @return array{slope: \SplFixedArray<float>, aspect: \SplFixedArray<float>, curvature: \SplFixedArray<float>}
     */
    public function compute(\SplFixedArray $elevation, Grid $grid): array
    {
        $columns = $grid->columns;
        $rows = $grid->rows;
        $total = $columns * $rows;
        $cellSize = (float) $grid->cellSizeMeters;

        /** @var \SplFixedArray<float> $slope */
        $slope = new \SplFixedArray($total);
        /** @var \SplFixedArray<float> $aspect */
        $aspect = new \SplFixedArray($total);
        /** @var \SplFixedArray<float> $curvature */
        $curvature = new \SplFixedArray($total);

        for ($row = 0; $row < $rows; $row++) {
            $rowOffset = $row * $columns;
            $northOffset = min($rows - 1, $row + 1) * $columns;
            $southOffset = max(0, $row - 1) * $columns;

            for ($column = 0; $column < $columns; $column++) {
                $index = $rowOffset + $column;
                $west = max(0, $column - 1);
                $east = min($columns - 1, $column + 1);

                $center = $elevation[$index];
                $northWest = $elevation[$northOffset + $west];
                $north = $elevation[$northOffset + $column];
                $northEast = $elevation[$northOffset + $east];
                $westValue = $elevation[$rowOffset + $west];
                $eastValue = $elevation[$rowOffset + $east];
                $southWest = $elevation[$southOffset + $west];
                $south = $elevation[$southOffset + $column];
                $southEast = $elevation[$southOffset + $east];

                $dzdx = (($northEast + 2 * $eastValue + $southEast)
                        - ($northWest + 2 * $westValue + $southWest)) / (8 * $cellSize);
                $dzdy = (($northWest + 2 * $north + $northEast)
                        - ($southWest + 2 * $south + $southEast)) / (8 * $cellSize);

                $slope[$index] = rad2deg(atan(sqrt($dzdx ** 2 + $dzdy ** 2)));
                $aspect[$index] = ($dzdx === 0.0 && $dzdy === 0.0)
                    ? -1.0
                    : fmod(rad2deg(atan2(-$dzdx, -$dzdy)) + 360.0, 360.0);
                $curvature[$index] = (($north + $south + $eastValue + $westValue) - 4 * $center)
                    / ($cellSize ** 2) * 10_000;
            }
        }

        return ['slope' => $slope, 'aspect' => $aspect, 'curvature' => $curvature];
    }
}
