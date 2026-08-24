<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Application\Cartography\InspectLocation;
use App\Application\Cartography\LayerGridQuery;
use App\Application\Cartography\ProjectScoreHorizon;
use App\Application\Cartography\RenderLayerGrid;
use App\Domain\Cartography\MapLayer;
use App\Domain\Geo\BoundingBox;
use App\Domain\Geo\Coordinates;
use App\Domain\Geo\SurveyArea;
use App\Domain\Mycology\ScoringMode;
use App\Domain\Mycology\SpeciesCatalog;
use App\Domain\Terrain\TerrainCellStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
final class MapController
{
    private const DEFAULT_MAX_CELLS = 70_000;

    public function __construct(
        private readonly SurveyArea $area,
        private readonly RenderLayerGrid $renderLayerGrid,
        private readonly InspectLocation $inspectLocation,
        private readonly ProjectScoreHorizon $projectScoreHorizon,
        private readonly SpeciesCatalog $speciesCatalog,
        private readonly TerrainCellStore $cellStore,
    ) {
    }

    #[Route('/context', name: 'context', methods: ['GET'])]
    public function context(): JsonResponse
    {
        $grid = $this->cellStore->storedGrid();
        $timezone = new \DateTimeZone('Europe/Paris');
        $today = new \DateTimeImmutable('today', $timezone);

        return new JsonResponse([
            'area' => $this->area->toArray(),
            'ready' => !$this->cellStore->isEmpty(),
            'projection' => [
                'horizonDays' => ProjectScoreHorizon::HORIZON_DAYS,
                'from' => $today->format('Y-m-d'),
                'to' => $today->modify(sprintf('+%d days', ProjectScoreHorizon::HORIZON_DAYS))->format('Y-m-d'),
            ],
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
            $scoringMode = $this->scoringModeFrom($request);
        } catch (\ValueError|\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $view = ($this->renderLayerGrid)(new LayerGridQuery(
            viewport: $viewport,
            layer: $layer,
            speciesId: $speciesId,
            maxCells: $maxCells,
            date: $this->projectionDateFrom($request),
            scoringMode: $scoringMode,
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

        try {
            $scoringMode = $this->scoringModeFrom($request);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $report = ($this->inspectLocation)(
            new Coordinates((float) $latitude, (float) $longitude),
            $speciesId,
            $this->projectionDateFrom($request),
            $scoringMode,
        );

        if ($report === null) {
            return new JsonResponse([
                'error' => 'Aucune donnée précalculée à cet endroit.',
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($report);
    }

    #[Route('/projection', name: 'projection', methods: ['GET'])]
    public function projection(Request $request): JsonResponse
    {
        if ($this->cellStore->isEmpty()) {
            return new JsonResponse([
                'error' => 'Données non précalculées. Lancez « php bin/console app:precompute ».',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $viewport = $this->viewportFrom($request);
            $speciesId = $request->query->getString('species', 'cepe');
            if (!$this->speciesCatalog->has($speciesId)) {
                throw new \InvalidArgumentException(sprintf('Espèce inconnue : %s', $speciesId));
            }
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $timezone = new \DateTimeZone('Europe/Paris');
        $today = new \DateTimeImmutable('today', $timezone);
        $payload = ($this->projectScoreHorizon)($viewport, $speciesId, $today);

        return new JsonResponse($payload);
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

    private function scoringModeFrom(Request $request): ScoringMode
    {
        $raw = $request->query->getString('mode', ScoringMode::Moment->value);
        if ($raw === '') {
            return ScoringMode::Moment;
        }

        try {
            return ScoringMode::from($raw);
        } catch (\ValueError) {
            throw new \InvalidArgumentException(sprintf('Mode de score inconnu : %s', $raw));
        }
    }

    /** Clamps the requested day to today … today+HORIZON_DAYS (Europe/Paris). */
    private function projectionDateFrom(Request $request): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone('Europe/Paris');
        $today = new \DateTimeImmutable('today', $timezone);
        $latest = $today->modify(sprintf('+%d days', ProjectScoreHorizon::HORIZON_DAYS));
        $raw = $request->query->getString('date');

        if ($raw === '') {
            return $today->setTime(12, 0);
        }

        try {
            $date = (new \DateTimeImmutable($raw, $timezone))->setTime(12, 0);
        } catch (\Throwable) {
            return $today->setTime(12, 0);
        }

        if ($date < $today) {
            return $today->setTime(12, 0);
        }
        if ($date > $latest) {
            return $latest->setTime(12, 0);
        }

        return $date;
    }
}
