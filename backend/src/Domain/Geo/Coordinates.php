<?php

declare(strict_types=1);

namespace App\Domain\Geo;

final readonly class Coordinates
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
    }

    public function distanceTo(self $other): float
    {
        $earthRadius = 6_371_000.0;
        $dLat = deg2rad($other->latitude - $this->latitude);
        $dLng = deg2rad($other->longitude - $this->longitude);
        $meanLat = deg2rad(($this->latitude + $other->latitude) / 2);

        $x = $dLng * cos($meanLat);

        return sqrt($dLat ** 2 + $x ** 2) * $earthRadius;
    }
}
