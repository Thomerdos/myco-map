<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

use App\Domain\Terrain\CanopyClosure;
use App\Domain\Terrain\ForestCover;
use App\Domain\Terrain\HostTree;

final readonly class Species
{
    /**
     * @param list<HarvestWindow> $harvestWindows
     * @param array<int, float> $coverAffinity ForestCover value => 0..1 (fallback when the host is unknown)
     * @param array<int, float> $hostAffinity HostTree value => 0..1 (used when the stand names a host)
     * @param array<int, float> $canopyAffinity CanopyClosure value => multiplier around 1
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
        /** First day after a soaking rain when fruitbodies start to be plausible. */
        public int $flushDelayMinDays = 7,
        /** Day after the soaking rain when fruiting usually peaks for this species. */
        public int $flushDelayPeakDays = 11,
        /** Beyond this, the flush from that rain is largely spent. */
        public int $flushDelayMaxDays = 18,
        public array $hostAffinity = [],
        public array $canopyAffinity = [],
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

    /**
     * Coarse leaf-type affinity is the fallback. A known host replaces it (the host is
     * what actually determines whether an ectomycorrhizal species can be present) except
     * on open ground, which must stay collapsed. Closed canopy then slightly lifts most
     * forest species; open canopy is slightly lower, except for morels.
     */
    public function coverSuitability(
        ForestCover $cover,
        HostTree $host = HostTree::Unknown,
        CanopyClosure $canopy = CanopyClosure::Unknown,
    ): float {
        $score = $this->coverAffinity[$cover->value] ?? 0.1;

        if ($host->isKnown() && $cover->isForest()) {
            $score = $this->hostAffinity[$host->value] ?? $score;
        }

        $score *= $this->canopyModifier($canopy);

        return max(0.0, min(1.0, $score));
    }

    private function canopyModifier(CanopyClosure $canopy): float
    {
        if (isset($this->canopyAffinity[$canopy->value])) {
            return $this->canopyAffinity[$canopy->value];
        }

        return match ($canopy) {
            CanopyClosure::Unknown => 1.0,
            CanopyClosure::Closed => 1.0,
            // Litter and understorey microclimate hold better under a closed canopy.
            // Morels (requiresForest = false) like disturbed / open edges instead.
            CanopyClosure::Open => $this->requiresForest ? 0.88 : 1.08,
        };
    }
}
