<?php

declare(strict_types=1);

namespace App\Application\Cartography;

use App\Domain\Cartography\LayerLegend;
use App\Domain\Cartography\MapLayer;
use App\Domain\Geo\BoundingBox;

final readonly class LayerGridView
{
    /**
     * @param list<float|null> $values row-major, first row is the northern edge
     * @param list<array<string, mixed>> $highlights
     * @param array<string, mixed> $statistics
     * @param array<string, mixed>|null $species
     */
    public function __construct(
        public MapLayer $layer,
        public LayerLegend $legend,
        public BoundingBox $bounds,
        public int $columns,
        public int $rows,
        public int $cellSizeMeters,
        public array $values,
        public array $statistics,
        public array $highlights,
        public array $weather,
        public ?array $species,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'layer' => $this->layer->value,
            'layerLabel' => $this->layer->label(),
            'legend' => $this->legend->toArray(),
            'bounds' => $this->bounds->toArray(),
            'columns' => $this->columns,
            'rows' => $this->rows,
            'cellSize' => $this->cellSizeMeters,
            'values' => $this->values,
            'statistics' => $this->statistics,
            'highlights' => $this->highlights,
            'weather' => $this->weather,
            'species' => $this->species,
        ];
    }
}
