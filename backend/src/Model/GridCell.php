<?php

namespace App\Model;

final class GridCell
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public readonly string $id,
        public readonly float $lat,
        public readonly float $lng,
        public readonly float $score,
        public readonly string $level,
        public readonly array $reasons,
        public readonly ?TerrainData $terrain = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'score' => round($this->score, 1),
            'level' => $this->level,
            'reasons' => $this->reasons,
            'terrain' => $this->terrain?->toArray(),
        ];
    }
}

final class TerrainData
{
    public function __construct(
        public readonly float $elevation,
        public readonly float $slope,
        public readonly float $aspect,
        public readonly string $aspectLabel,
        public readonly string $forestType,
        public readonly float $forestConfidence,
    ) {
    }

    public function toArray(): array
    {
        return [
            'elevation' => round($this->elevation),
            'slope' => round($this->slope, 1),
            'aspect' => round($this->aspect),
            'aspectLabel' => $this->aspectLabel,
            'forestType' => $this->forestType,
            'forestConfidence' => round($this->forestConfidence, 2),
        ];
    }
}
