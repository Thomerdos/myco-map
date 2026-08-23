<?php

declare(strict_types=1);

namespace App\Infrastructure\Raster;

use App\Domain\Geo\Grid;

/**
 * Two-pass chamfer distance transform: cheap approximation of the euclidean distance
 * to the nearest seed cell, in metres.
 */
final readonly class ChamferDistance
{
    private const UNREACHABLE = 1_000_000.0;
    private const DIAGONAL = 1.41421356;
    private const CAP_METERS = 9999;

    /**
     * @param \SplFixedArray<int> $source
     * @param callable(int): bool $isSeed
     * @return \SplFixedArray<int>
     */
    public function compute(\SplFixedArray $source, Grid $grid, callable $isSeed): \SplFixedArray
    {
        $columns = $grid->columns;
        $rows = $grid->rows;
        $total = $columns * $rows;

        /** @var \SplFixedArray<float> $distance */
        $distance = new \SplFixedArray($total);
        for ($i = 0; $i < $total; $i++) {
            $distance[$i] = $isSeed($source[$i]) ? 0.0 : self::UNREACHABLE;
        }

        for ($row = 0; $row < $rows; $row++) {
            $rowOffset = $row * $columns;
            $previousOffset = $rowOffset - $columns;

            for ($column = 0; $column < $columns; $column++) {
                $index = $rowOffset + $column;
                if ($distance[$index] === 0.0) {
                    continue;
                }

                $best = $distance[$index];
                if ($row > 0) {
                    $best = min($best, $distance[$previousOffset + $column] + 1);
                    if ($column > 0) {
                        $best = min($best, $distance[$previousOffset + $column - 1] + self::DIAGONAL);
                    }
                    if ($column + 1 < $columns) {
                        $best = min($best, $distance[$previousOffset + $column + 1] + self::DIAGONAL);
                    }
                }
                if ($column > 0) {
                    $best = min($best, $distance[$index - 1] + 1);
                }

                $distance[$index] = $best;
            }
        }

        /** @var \SplFixedArray<int> $metres */
        $metres = new \SplFixedArray($total);
        $cellSize = $grid->cellSizeMeters;

        for ($row = $rows - 1; $row >= 0; $row--) {
            $rowOffset = $row * $columns;
            $nextOffset = $rowOffset + $columns;

            for ($column = $columns - 1; $column >= 0; $column--) {
                $index = $rowOffset + $column;
                $best = $distance[$index];

                if ($best !== 0.0) {
                    if ($row + 1 < $rows) {
                        $best = min($best, $distance[$nextOffset + $column] + 1);
                        if ($column > 0) {
                            $best = min($best, $distance[$nextOffset + $column - 1] + self::DIAGONAL);
                        }
                        if ($column + 1 < $columns) {
                            $best = min($best, $distance[$nextOffset + $column + 1] + self::DIAGONAL);
                        }
                    }
                    if ($column + 1 < $columns) {
                        $best = min($best, $distance[$index + 1] + 1);
                    }
                    $distance[$index] = $best;
                }

                $metres[$index] = $best >= self::UNREACHABLE
                    ? self::CAP_METERS
                    : min(self::CAP_METERS, (int) round($best * $cellSize));
            }
        }

        return $metres;
    }
}
