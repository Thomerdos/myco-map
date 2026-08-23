<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Application\Cartography\InspectLocation;
use App\Application\Cartography\LayerGridQuery;
use App\Application\Cartography\RenderLayerGrid;
use App\Domain\Cartography\MapLayer;
use App\Domain\Geo\BoundingBox;
use App\Domain\Geo\Coordinates;
use App\Domain\Geo\SurveyArea;
use App\Domain\Mycology\SpeciesCatalog;
use App\Domain\Terrain\TerrainCellStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
final class MapController
{
    private const DEFAULT_MAX_CELLS = 45_000;

    public function __construct(
        private readonly SurveyArea $area,
        private readonly RenderLayerGrid $renderLayerGrid,
        private readonly InspectLocation $inspectLocation,
        private readonly SpeciesCatalog $speciesCatalog,
        private readonly TerrainCellStore $cellStore,
    ) {
    }

    #[Route('/context', name: 'context', methods: ['GET'])]
    public function context(): JsonResponse
    {
        $grid = $this->cellStore->storedGrid();

        return new JsonResponse([
            'area' => $this->area->toArray(),
            'ready' => !$this->cellStore->isEmpty(),
            'grid' => $grid === null ? null : [
                'cellSize' => $grid->cellSizeMeters,
                'columns' => $grid->columns,
                'rows' => $grid->rows,
                'bounds' => $grid->bounds->toArray(),
            ],
            'layers' => array_map(
                static fn (MapLayer $layer): array => [
                    'id' => $layer->value,
                    'label' => $layer->label(),
                    'categorical' => $layer->isCategorical(),
                    'unit' => $layer->unit(),
                    'requiresSpecies' => $layer->requiresSpecies(),
                ],
                MapLayer::cases(),
            ),
            'species' => array_map(
                static fn ($species): array => [
                    'id' => $species->id,
                    'name' => $species->commonName,
                    'scientificName' => $species->scientificName,
                    'summary' => $species->summary,
                    'hostTrees' => $species->hostTrees,
                    'harvestWindows' => array_map(
                        static fn ($window): array => $window->toArray(),
                        $species->harvestWindows,
                    ),
                    'altitude' => [
                        'min' => $species->altitude->minimum,
                        'optimumLow' => $species->altitude->optimumLow,
                        'optimumHigh' => $species->altitude->optimumHigh,
                        'max' => $species->altitude->maximum,
                    ],
                ],
                $this->speciesCatalog->all(),
            ),
        ]);
    }

    #[Route('/layer', name: 'layer', methods: ['GET'])]
    public function layer(Request $request): JsonResponse
    {
        if ($this->cellStore->isEmpty()) {
            return new JsonResponse([
                'error' => 'Données non précalculées. Lancez « php bin/console app:precompute ».',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $viewport = $this->viewportFrom($request);
            $layer = MapLayer::from($request->query->getString('layer', MapLayer::Potential->value));
            $speciesId = $request->query->getString('species', 'cepe');

            if (!$this->speciesCatalog->has($speciesId)) {
                throw new \InvalidArgumentException(sprintf('Espèce inconnue : %s', $speciesId));
            }

            $maxCells = min(90_000, max(2_000, $request->query->getInt('maxCells', self::DEFAULT_MAX_CELLS)));
        } catch (\ValueError|\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $view = ($this->renderLayerGrid)(new LayerGridQuery(
            viewport: $viewport,
            layer: $layer,
            speciesId: $speciesId,
            maxCells: $maxCells,
            date: $this->dateFrom($request),
        ));

        if ($view === null) {
            return new JsonResponse([
                'error' => 'La vue demandée est en dehors de la zone couverte.',
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($view->toArray());
    }

    #[Route('/location', name: 'location', methods: ['GET'])]
    public function location(Request $request): JsonResponse
    {
        $latitude = $request->query->get('lat');
        $longitude = $request->query->get('lng');

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return new JsonResponse(['error' => 'Coordonnées manquantes.'], Response::HTTP_BAD_REQUEST);
        }

        $speciesId = $request->query->getString('species', 'cepe');
        if (!$this->speciesCatalog->has($speciesId)) {
            return new JsonResponse(['error' => 'Espèce inconnue.'], Response::HTTP_BAD_REQUEST);
        }

        $report = ($this->inspectLocation)(
            new Coordinates((float) $latitude, (float) $longitude),
            $speciesId,
            $this->dateFrom($request),
        );

        if ($report === null) {
            return new JsonResponse([
                'error' => 'Aucune donnée précalculée à cet endroit.',
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($report);
    }

    private function viewportFrom(Request $request): BoundingBox
    {
        foreach (['south', 'west', 'north', 'east'] as $key) {
            if (!is_numeric($request->query->get($key))) {
                throw new \InvalidArgumentException('Emprise de carte incomplète.');
            }
        }

        return new BoundingBox(
            south: (float) $request->query->get('south'),
            west: (float) $request->query->get('west'),
            north: (float) $request->query->get('north'),
            east: (float) $request->query->get('east'),
        );
    }

    private function dateFrom(Request $request): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone('Europe/Paris');
        $raw = $request->query->getString('date');

        if ($raw === '') {
            return new \DateTimeImmutable('now', $timezone);
        }

        try {
            return new \DateTimeImmutable($raw, $timezone);
        } catch (\Throwable) {
            return new \DateTimeImmutable('now', $timezone);
        }
    }
}
