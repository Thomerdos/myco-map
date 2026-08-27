<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

use App\Domain\Terrain\CanopyClosure;
use App\Domain\Terrain\ForestCover;
use App\Domain\Terrain\HostTree;
use App\Domain\Terrain\Substrate;

final readonly class Species
{
    /**
     * @param list<HarvestWindow> $harvestWindows
     * @param array<int, float> $coverAffinity ForestCover value => 0..1 (fallback when the host is unknown)
     * @param array<int, float> $hostAffinity HostTree value => 0..1 (used when the stand names a host)
     * @param array<int, float> $standDensityAffinity CanopyClosure value => 0..1 (FO/FF fallback)
     * @param array<int, float> $geologyAffinity Substrate value => 0..1 (Charm-50 fallback)
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
        public int $flushDelayMinDays = 7,
        public int $flushDelayPeakDays = 11,
        public int $flushDelayMaxDays = 18,
        public int $flushPersistDays = 4,
        public array $hostAffinity = [],
        public array $standDensityAffinity = [],
        public array $geologyAffinity = [],
        public ?CanopyDensityBand $canopyDensity = null,
        public ?PhBand $phOptimum = null,
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
     * Coarse leaf-type affinity is the fallback. A known host replaces it. Canopy density
     * is scored separately as {@see standDensitySuitability()} to avoid double-counting.
     */
    public function coverSuitability(
        ForestCover $cover,
        HostTree $host = HostTree::Unknown,
    ): float {
        $score = $this->coverAffinity[$cover->value] ?? 0.1;

        if ($host->isKnown() && $cover->isForest()) {
            $score = $this->hostAffinity[$host->value] ?? $score;
        }

        return max(0.0, min(1.0, $score));
    }

    /**
     * Continuous tree-cover percent (Copernicus TCD) when known; otherwise BD Forêt FO/FF.
     * Intermediate cover is closer to published basal-area optima (~15–20 m²/ha).
     */
    public function standDensitySuitability(
        ForestCover $cover,
        CanopyClosure $canopy,
        ?int $canopyCoverPercent = null,
    ): float {
        if (!$cover->isForest()) {
            return $this->requiresForest ? 0.08 : 0.55;
        }

        if ($canopyCoverPercent !== null) {
            $band = $this->canopyDensity ?? new CanopyDensityBand(
                closedFloor: $this->requiresForest ? 0.66 : 0.50,
                sparseFloor: $this->requiresForest ? 0.20 : 0.60,
            );

            return max(0.0, min(1.0, $band->suitability($canopyCoverPercent)));
        }

        if (isset($this->standDensityAffinity[$canopy->value])) {
            return max(0.0, min(1.0, $this->standDensityAffinity[$canopy->value]));
        }

        return match ($canopy) {
            CanopyClosure::Unknown => 0.55,
            CanopyClosure::Open => $this->requiresForest ? 0.85 : 0.90,
            CanopyClosure::Closed => $this->requiresForest ? 0.66 : 0.62,
        };
    }

    /**
     * Prefers continuous EcoDataCube pH when known; otherwise Charm-50 substrate classes.
     */
    public function geologySuitability(Substrate $substrate, ?float $soilPh = null): float
    {
        if ($soilPh !== null) {
            $band = $this->phOptimum ?? new PhBand();

            return max(0.0, min(1.0, $band->suitability($soilPh)));
        }

        if (isset($this->geologyAffinity[$substrate->value])) {
            return max(0.0, min(1.0, $this->geologyAffinity[$substrate->value]));
        }

        // Neutral when the species has no stated preference or the cell is unknown.
        return match ($substrate) {
            Substrate::Unknown => 0.55,
            default => 0.70,
        };
    }
}
