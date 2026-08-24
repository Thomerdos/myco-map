<?php

declare(strict_types=1);

namespace App\Infrastructure\Raster;

use App\Domain\Geo\Grid;
use App\Domain\Terrain\AccessThreshold;

/**
 * Walking distance from a parkable way, following OSM paths then a short slope-aware
 * approach into the stand. Crow-flies chamfer is not used: ridges without a path stay
 * unreachable.
 */
final readonly class PathNetworkAccess
{
    private const UNREACHABLE = 1_000_000.0;

    /**
     * @param \SplFixedArray<int> $park 1 on parkable cells
     * @param \SplFixedArray<int> $path 1 on walkable cells
     * @param \SplFixedArray<float> $slope degrees
     * @return \SplFixedArray<int> metres, {@see AccessThreshold::UNREACHABLE} if out of budget
     */
    public function compute(
        \SplFixedArray $park,
        \SplFixedArray $path,
        \SplFixedArray $slope,
        Grid $grid,
    ): \SplFixedArray {
        $along = $this->alongPaths($park, $path, $slope, $grid);

        return $this->withApproach($along, $path, $slope, $grid);
    }

    /**
     * @param \SplFixedArray<int> $park
     * @param \SplFixedArray<int> $path
     * @param \SplFixedArray<float> $slope
     * @return \SplFixedArray<float>
     */
    private function alongPaths(
        \SplFixedArray $park,
        \SplFixedArray $path,
        \SplFixedArray $slope,
        Grid $grid,
    ): \SplFixedArray {
        $columns = $grid->columns;
        $rows = $grid->rows;
        $total = $columns * $rows;
        $cellSize = (float) $grid->cellSizeMeters;
        $budget = (float) AccessThreshold::ALONG_PATH_METERS;

        /** @var \SplFixedArray<float> $distance */
        $distance = new \SplFixedArray($total);
        $queue = new MinCostQueue();

        for ($index = 0; $index < $total; $index++) {
            $distance[$index] = self::UNREACHABLE;
            if ($path[$index] !== 1 || !$this->isTrailhead($index, $park, $path, $columns, $rows)) {
                continue;
            }
            $distance[$index] = 0.0;
            $queue->insert($index, 0.0);
        }

        $offsets = $this->neighbourOffsets();
        $settled = [];

        while (!$queue->isEmpty()) {
            $index = (int) $queue->extract();
            if (isset($settled[$index])) {
                continue;
            }
            $settled[$index] = true;
            $cost = $distance[$index];
            if ($cost >= $budget) {
                continue;
            }

            $row = intdiv($index, $columns);
            $column = $index % $columns;

            foreach ($offsets as [$dRow, $dColumn, $stepCells]) {
                $nextRow = $row + $dRow;
                $nextColumn = $column + $dColumn;
                if ($nextRow < 0 || $nextColumn < 0 || $nextRow >= $rows || $nextColumn >= $columns) {
                    continue;
                }
                $neighbour = $nextRow * $columns + $nextColumn;
                if ($path[$neighbour] !== 1 || isset($settled[$neighbour])) {
                    continue;
                }

                $step = $cellSize * $stepCells * $this->trailSlopeFactor(
                    (float) $slope[$index],
                    (float) $slope[$neighbour],
                );
                $nextCost = $cost + $step;
                if ($nextCost >= $distance[$neighbour] || $nextCost > $budget) {
                    continue;
                }
                $distance[$neighbour] = $nextCost;
                $queue->insert($neighbour, $nextCost);
            }
        }

        return $distance;
    }

    /**
     * @param \SplFixedArray<float> $along
     * @param \SplFixedArray<int> $path
     * @param \SplFixedArray<float> $slope
     * @return \SplFixedArray<int>
     */
    private function withApproach(
        \SplFixedArray $along,
        \SplFixedArray $path,
        \SplFixedArray $slope,
        Grid $grid,
    ): \SplFixedArray {
        $columns = $grid->columns;
        $rows = $grid->rows;
        $total = $columns * $rows;
        $cellSize = (float) $grid->cellSizeMeters;
        $cap = AccessThreshold::UNREACHABLE;
        $approachBudget = (float) AccessThreshold::APPROACH_METERS;

        /** @var \SplFixedArray<int> $access */
        $access = new \SplFixedArray($total);
        /** @var \SplFixedArray<float> $best */
        $best = new \SplFixedArray($total);
        $queue = new MinCostQueue();

        for ($index = 0; $index < $total; $index++) {
            $best[$index] = self::UNREACHABLE;
            $access[$index] = $cap;
            if ($along[$index] >= self::UNREACHABLE) {
                continue;
            }
            $best[$index] = $along[$index];
            $access[$index] = min($cap, (int) round($along[$index]));
            $queue->insert([$index, 0.0], $along[$index]);
        }

        $offsets = $this->neighbourOffsets();
        $settled = [];

        while (!$queue->isEmpty()) {
            /** @var array{0: int, 1: float} $state */
            $state = $queue->extract();
            [$index, $approach] = $state;
            if (isset($settled[$index])) {
                continue;
            }
            $settled[$index] = true;
            $cost = $best[$index];

            $row = intdiv($index, $columns);
            $column = $index % $columns;

            foreach ($offsets as [$dRow, $dColumn, $stepCells]) {
                $nextRow = $row + $dRow;
                $nextColumn = $column + $dColumn;
                if ($nextRow < 0 || $nextColumn < 0 || $nextRow >= $rows || $nextColumn >= $columns) {
                    continue;
                }
                $neighbour = $nextRow * $columns + $nextColumn;
                if ($path[$neighbour] === 1 || isset($settled[$neighbour])) {
                    continue;
                }
                if ((float) $slope[$neighbour] >= AccessThreshold::CLIFF_DEGREES) {
                    continue;
                }

                $step = $cellSize * $stepCells * $this->approachSlopeFactor((float) $slope[$neighbour]);
                $nextApproach = $approach + $step;
                if ($nextApproach > $approachBudget) {
                    continue;
                }
                $nextCost = $cost + $step;
                if ($nextCost >= $best[$neighbour]) {
                    continue;
                }
                $best[$neighbour] = $nextCost;
                $access[$neighbour] = min($cap, (int) round($nextCost));
                $queue->insert([$neighbour, $nextApproach], $nextCost);
            }
        }

        return $access;
    }

    /**
     * @param \SplFixedArray<int> $park
     * @param \SplFixedArray<int> $path
     */
    private function isTrailhead(int $index, \SplFixedArray $park, \SplFixedArray $path, int $columns, int $rows): bool
    {
        if ($path[$index] !== 1) {
            return false;
        }
        if ($park[$index] === 1) {
            return true;
        }

        $row = intdiv($index, $columns);
        $column = $index % $columns;
        for ($dRow = -1; $dRow <= 1; $dRow++) {
            for ($dColumn = -1; $dColumn <= 1; $dColumn++) {
                if ($dRow === 0 && $dColumn === 0) {
                    continue;
                }
                $nextRow = $row + $dRow;
                $nextColumn = $column + $dColumn;
                if ($nextRow < 0 || $nextColumn < 0 || $nextRow >= $rows || $nextColumn >= $columns) {
                    continue;
                }
                if ($park[$nextRow * $columns + $nextColumn] === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<array{0: int, 1: int, 2: float}> */
    private function neighbourOffsets(): array
    {
        $diag = 1.41421356;

        return [
            [-1, -1, $diag], [-1, 0, 1.0], [-1, 1, $diag],
            [0, -1, 1.0], [0, 1, 1.0],
            [1, -1, $diag], [1, 0, 1.0], [1, 1, $diag],
        ];
    }

    private function trailSlopeFactor(float $from, float $to): float
    {
        $slope = max($from, $to);

        return 1.0 + ($slope / 35.0) ** 2;
    }

    private function approachSlopeFactor(float $slope): float
    {
        return 1.0 + ($slope / 25.0) ** 2;
    }
}
