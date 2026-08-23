<?php

declare(strict_types=1);

namespace App\Domain\Geo;

/**
 * The single continuous area the application covers: the Grenoble basin with the
 * Chartreuse, Belledonne and Vercors ranges around it.
 */
final readonly class SurveyArea
{
    public function __construct(
        public string $name,
        public BoundingBox $bounds,
        public Coordinates $defaultCenter,
        public int $defaultZoom,
        public int $cellSizeMeters,
    ) {
    }

    public function grid(): Grid
    {
        return new Grid($this->bounds, $this->cellSizeMeters);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'bounds' => $this->bounds->toArray(),
            'center' => [
                'lat' => $this->defaultCenter->latitude,
                'lng' => $this->defaultCenter->longitude,
            ],
            'zoom' => $this->defaultZoom,
            'cellSize' => $this->cellSizeMeters,
        ];
    }
}
