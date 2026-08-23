<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

final readonly class ForestPolygon
{
    /**
     * @param list<list<array{0: float, 1: float}>> $outerRings lat/lng pairs
     * @param list<list<array{0: float, 1: float}>> $innerRings clearings to carve out
     */
    public function __construct(
        public ForestCover $cover,
        public array $outerRings,
        public array $innerRings = [],
    ) {
    }
}
