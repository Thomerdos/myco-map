<?php

declare(strict_types=1);

namespace App\Domain\Cartography;

final readonly class LayerLegend
{
    /** @param list<array{value: float|int, label: string, color: string}> $stops */
    public function __construct(
        public string $title,
        public ?string $unit,
        public bool $categorical,
        public array $stops,
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
        ];
    }
}
