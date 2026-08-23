<?php

namespace App\Service;

use App\Model\GridCell;
use App\Model\Region;
use App\Model\Species;
use App\Repository\RegionRepository;
use App\Repository\SpeciesRepository;

final class MapAnalysisService
{
    public function __construct(
        private readonly GridService $gridService,
        private readonly TerrainService $terrainService,
        private readonly ForestService $forestService,
        private readonly WeatherService $weatherService,
        private readonly SpeciesScoringService $scoringService,
        private readonly RegionRepository $regionRepository,
        private readonly SpeciesRepository $speciesRepository,
    ) {
    }

    /**
     * @return array{
     *   region: array<string, mixed>,
     *   species: array<string, mixed>,
     *   weather: array<string, mixed>,
     *   resolution: int,
     *   cells: list<array<string, mixed>>,
     *   stats: array<string, mixed>
     * }
     */
    public function analyze(
        string $regionId,
        string $speciesId,
        ?float $south = null,
        ?float $west = null,
        ?float $north = null,
        ?float $east = null,
        int $resolution = 500,
    ): array {
        $region = $this->regionRepository->find($regionId);
        $species = $this->speciesRepository->find($speciesId);

        if ($region === null || $species === null) {
            throw new \InvalidArgumentException('Région ou espèce inconnue.');
        }

        $south ??= $region->south;
        $west ??= $region->west;
        $north ??= $region->north;
        $east ??= $region->east;

        $south = max($south, $region->south);
        $west = max($west, $region->west);
        $north = min($north, $region->north);
        $east = min($east, $region->east);

        $resolution = $this->gridService->clampResolution($resolution);
        $points = $this->gridService->generateGrid($south, $west, $north, $east, $resolution);

        $weather = $this->weatherService->fetchConditions($region->centerLat, $region->centerLng);
        $forestData = $this->forestService->fetchForestTypes($south, $west, $north, $east);
        $terrainData = $this->terrainService->fetchTerrainBatch($points);

        $cells = [];
        foreach ($points as $point) {
            $terrain = $terrainData[$point['id']] ?? [
                'elevation' => 1000,
                'slope' => 15,
                'aspect' => 0,
                'aspectLabel' => 'N',
            ];
            $forest = $this->forestService->classifyPoint($point['lat'], $point['lng'], $forestData);

            $cell = $this->scoringService->scoreCell(
                $species,
                $terrain,
                $forest,
                $weather,
                $point['lat'],
                $point['lng'],
                $point['id'],
            );
            $cells[] = $cell->toArray();
        }

        usort($cells, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return [
            'region' => $this->regionToArray($region),
            'species' => $this->speciesToArray($species),
            'weather' => $weather,
            'resolution' => $resolution,
            'bounds' => compact('south', 'west', 'north', 'east'),
            'cells' => $cells,
            'stats' => $this->computeStats($cells),
        ];
    }

    /** @param list<array<string, mixed>> $cells */
    private function computeStats(array $cells): array
    {
        if ($cells === []) {
            return ['count' => 0, 'avgScore' => 0, 'topScore' => 0];
        }

        $scores = array_column($cells, 'score');

        return [
            'count' => count($cells),
            'avgScore' => round(array_sum($scores) / count($scores), 1),
            'topScore' => max($scores),
            'excellent' => count(array_filter($cells, static fn (array $c): bool => $c['level'] === 'excellent')),
            'bon' => count(array_filter($cells, static fn (array $c): bool => $c['level'] === 'bon')),
        ];
    }

    /** @return array<string, mixed> */
    private function regionToArray(Region $region): array
    {
        return [
            'id' => $region->id,
            'name' => $region->name,
            'bounds' => [
                'south' => $region->south,
                'west' => $region->west,
                'north' => $region->north,
                'east' => $region->east,
            ],
            'center' => ['lat' => $region->centerLat, 'lng' => $region->centerLng],
        ];
    }

    /** @return array<string, mixed> */
    private function speciesToArray(Species $species): array
    {
        $activeWindow = null;
        foreach ($species->harvestWindows as $window) {
            if ($this->weatherService->isInHarvestWindow($window['start'], $window['end'])) {
                $activeWindow = $window;
                break;
            }
        }

        return [
            'id' => $species->id,
            'name' => $species->name,
            'scientificName' => $species->scientificName,
            'description' => $species->description,
            'harvestWindows' => $species->harvestWindows,
            'inSeason' => $activeWindow !== null,
            'activeWindow' => $activeWindow,
        ];
    }
}
