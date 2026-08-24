<?php

declare(strict_types=1);

namespace App\Infrastructure\Raster;

/**
 * Min-heap over Dijkstra costs. SplPriorityQueue extracts the highest priority by
 * default; flipping compare makes lower walking cost come out first.
 */
final class MinCostQueue extends \SplPriorityQueue
{
    public function compare(mixed $priority1, mixed $priority2): int
    {
        return $priority2 <=> $priority1;
    }
}
