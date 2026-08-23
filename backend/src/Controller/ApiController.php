<?php

namespace App\Controller;

use App\Repository\RegionRepository;
use App\Repository\SpeciesRepository;
use App\Service\MapAnalysisService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class ApiController extends AbstractController
{
    public function __construct(
        private readonly RegionRepository $regionRepository,
        private readonly SpeciesRepository $speciesRepository,
        private readonly MapAnalysisService $mapAnalysisService,
    ) {
    }

    #[Route('/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'app' => 'Forage Mapper',
            'version' => '0.1.0',
        ]);
    }

    #[Route('/regions', name: 'api_regions', methods: ['GET'])]
    public function regions(): JsonResponse
    {
        $regions = array_map(static fn ($r): array => [
            'id' => $r->id,
            'name' => $r->name,
            'bounds' => [
                'south' => $r->south,
                'west' => $r->west,
                'north' => $r->north,
                'east' => $r->east,
            ],
            'center' => ['lat' => $r->centerLat, 'lng' => $r->centerLng],
        ], $this->regionRepository->findAll());

        return $this->json(['regions' => $regions]);
    }

    #[Route('/species', name: 'api_species', methods: ['GET'])]
    public function species(): JsonResponse
    {
        $species = array_map(static fn ($s): array => [
            'id' => $s->id,
            'name' => $s->name,
            'scientificName' => $s->scientificName,
            'description' => $s->description,
            'harvestWindows' => $s->harvestWindows,
        ], $this->speciesRepository->findAll());

        return $this->json(['species' => $species]);
    }

    #[Route('/map', name: 'api_map', methods: ['GET'])]
    public function map(Request $request): JsonResponse
    {
        $regionId = (string) $request->query->get('region', 'chartreuse');
        $speciesId = (string) $request->query->get('species', 'cepe');
        $resolution = (int) $request->query->get('resolution', 500);

        $south = $request->query->has('south') ? (float) $request->query->get('south') : null;
        $west = $request->query->has('west') ? (float) $request->query->get('west') : null;
        $north = $request->query->has('north') ? (float) $request->query->get('north') : null;
        $east = $request->query->has('east') ? (float) $request->query->get('east') : null;

        try {
            $data = $this->mapAnalysisService->analyze(
                $regionId,
                $speciesId,
                $south,
                $west,
                $north,
                $east,
                $resolution,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Erreur lors de l\'analyse: ' . $e->getMessage()], 500);
        }

        return $this->json($data);
    }
}
