<?php

declare(strict_types=1);

namespace App\Domain\Geo;

final readonly class BoundingBox
{
    public function __construct(
        public float $south,
        public float $west,
        public float $north,
        public float $east,
    ) {
        if ($south >= $north || $west >= $east) {
            throw new \InvalidArgumentException('Bornes géographiques invalides.');
        }
    }

    public function intersect(self $other): ?self
    {
        $south = max($this->south, $other->south);
        $west = max($this->west, $other->west);
        $north = min($this->north, $other->north);
        $east = min($this->east, $other->east);

        if ($south >= $north || $west >= $east) {
            return null;
        }

        return new self($south, $west, $north, $east);
    }

    public function contains(Coordinates $point): bool
    {
        return $point->latitude >= $this->south
            && $point->latitude <= $this->north
            && $point->longitude >= $this->west
            && $point->longitude <= $this->east;
    }

    public function center(): Coordinates
    {
        return new Coordinates(
            ($this->south + $this->north) / 2,
            ($this->west + $this->east) / 2,
        );
    }

    /** @return array{south: float, west: float, north: float, east: float} */
    public function toArray(): array
    {
        return [
            'south' => $this->south,
            'west' => $this->west,
            'north' => $this->north,
            'east' => $this->east,
        ];
    }
}
