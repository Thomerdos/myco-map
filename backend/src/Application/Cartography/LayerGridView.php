<?php

declare(strict_types=1);

namespace App\Application\Cartography;

use App\Domain\Cartography\LayerLegend;
use App\Domain\Cartography\MapLayer;
use App\Domain\Geo\BoundingBox;
use App\Domain\Mycology\ScoringMode;

final readonly class LayerGridView
{
    /**
     * @param list<float|null> $values row-major, first row is the northern edge
     * @param list<array<string, mixed>> $sectors
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
        public array $sectors,
        public array $weather,
        public ?array $species,
        public ScoringMode $scoringMode = ScoringMode::Moment,
        public ?string $asOfDate = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $layerLabel = $this->layer === MapLayer::Potential && $this->scoringMode === ScoringMode::Habitat
            ? 'Potentiel d\'habitat'
            : $this->layer->label();

        return [
            'layer' => $this->layer->value,
            'layerLabel' => $layerLabel,
            'scoringMode' => $this->scoringMode->value,
            'asOfDate' => $this->asOfDate,
            'legend' => $this->legend->toArray(),
            'bounds' => $this->bounds->toArray(),
            'columns' => $this->columns,
            'rows' => $this->rows,
            'cellSize' => $this->cellSizeMeters,
            'values' => $this->values,
            'statistics' => $this->statistics,
            'sectors' => $this->sectors,
            'weather' => $this->weather,
            'species' => $this->species,
        ];
    }
}
