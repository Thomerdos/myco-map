<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

use App\Domain\Terrain\ForestCover;

final readonly class Species
{
    /**
     * @param list<HarvestWindow> $harvestWindows
     * @param array<int, float> $coverAffinity ForestCover value => 0..1
     */
    public function __construct(
        public string $id,
        public string $commonName,
        public string $scientificName,
        public string $summary,
        public string $hostTrees,
        public array $harvestWindows,
        public AltitudeBand $altitude,
        public SlopeBand $slope,
        public float $coolPreference,
        public array $coverAffinity,
        public EdgeAffinity $edgeAffinity,
        public float $moisturePreference,
        public bool $requiresForest = true,
    ) {
    }

    public function activeWindow(\DateTimeImmutable $date): ?HarvestWindow
    {
        foreach ($this->harvestWindows as $window) {
            if ($window->covers($date)) {
                return $window;
            }
        }

        return null;
    }

    public function coverSuitability(ForestCover $cover): float
    {
        return $this->coverAffinity[$cover->value] ?? 0.1;
    }
}
