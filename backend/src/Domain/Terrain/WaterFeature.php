<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

final readonly class WaterFeature
{
    /** @param list<array{0: float, 1: float}> $points lat/lng pairs */
    public function __construct(
        public array $points,
        public bool $isArea,
    ) {
    }
}
