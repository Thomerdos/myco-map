<?php

declare(strict_types=1);

namespace App\Domain\Cartography;

final readonly class LayerLegend
{
    /**
     * @param list<array{value: float|int, label: string, color: string}> $stops
     * @param bool $emphasiseTop only the upper end of the scale carries information, so the
     *                           renderer may fade the lower end out instead of veiling the map
     */
    public function __construct(
        public string $title,
        public ?string $unit,
        public bool $categorical,
        public array $stops,
        public bool $emphasiseTop = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'unit' => $this->unit,
            'categorical' => $this->categorical,
            'stops' => $this->stops,
            'emphasiseTop' => $this->emphasiseTop,
        ];
    }
}
