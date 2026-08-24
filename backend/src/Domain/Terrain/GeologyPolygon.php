<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

/**
 * One geological polygon ready to burn onto the terrain grid.
 *
 * @param list<list<array{0: float, 1: float}>> $outerRings lon/lat rings
 * @param list<list<array{0: float, 1: float}>> $innerRings
 */
final readonly class GeologyPolygon
{
    /**
     * @param list<list<array{0: float, 1: float}>> $outerRings
     * @param list<list<array{0: float, 1: float}>> $innerRings
     */
    public function __construct(
        public Substrate $substrate,
        public array $outerRings,
        public array $innerRings = [],
    ) {
    }
}
