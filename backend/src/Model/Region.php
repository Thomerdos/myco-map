<?php

namespace App\Model;

final class Region
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly float $south,
        public readonly float $west,
        public readonly float $north,
        public readonly float $east,
        public readonly float $centerLat,
        public readonly float $centerLng,
    ) {
    }

    public function contains(float $lat, float $lng): bool
    {
        return $lat >= $this->south && $lat <= $this->north
            && $lng >= $this->west && $lng <= $this->east;
    }
}
