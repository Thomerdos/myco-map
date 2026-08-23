<?php

namespace App\Model;

final class Species
{
    /**
     * @param array<int, array{start: string, end: string, peak: bool, label: string}> $harvestWindows
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $scientificName,
        public readonly string $description,
        public readonly array $harvestWindows,
    ) {
    }
}
