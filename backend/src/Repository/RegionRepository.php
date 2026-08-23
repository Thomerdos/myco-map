<?php

namespace App\Repository;

use App\Model\Region;

final class RegionRepository
{
    /** @var array<string, Region> */
    private array $regions;

    public function __construct()
    {
        $this->regions = [
            'chartreuse' => new Region(
                id: 'chartreuse',
                name: 'Chartreuse',
                south: 45.14,
                west: 5.62,
                north: 45.42,
                east: 6.08,
                centerLat: 45.28,
                centerLng: 5.85,
            ),
            'belledonne' => new Region(
                id: 'belledonne',
                name: 'Belledonne',
                south: 45.04,
                west: 5.82,
                north: 45.36,
                east: 6.28,
                centerLat: 45.20,
                centerLng: 6.05,
            ),
            'vercors' => new Region(
                id: 'vercors',
                name: 'Vercors',
                south: 44.72,
                west: 5.38,
                north: 45.18,
                east: 5.88,
                centerLat: 44.95,
                centerLng: 5.63,
            ),
        ];
    }

    /** @return list<Region> */
    public function findAll(): array
    {
        return array_values($this->regions);
    }

    public function find(string $id): ?Region
    {
        return $this->regions[$id] ?? null;
    }
}
