<?php

namespace App\Service;

final class GridService
{
    private const EARTH_RADIUS_M = 6371000;

    /**
     * @return list<array{lat: float, lng: float, id: string}>
     */
    public function generateGrid(
        float $south,
        float $west,
        float $north,
        float $east,
        int $resolutionMeters,
        int $maxCells = 800,
    ): array {
        $latMid = ($south + $north) / 2;
        $latStep = $resolutionMeters / self::EARTH_RADIUS_M * (180 / M_PI);
        $lngStep = $resolutionMeters / (self::EARTH_RADIUS_M * cos(deg2rad($latMid))) * (180 / M_PI);

        $cells = [];
        $lat = $south + $latStep / 2;
        while ($lat <= $north) {
            $lng = $west + $lngStep / 2;
            while ($lng <= $east) {
                $cells[] = [
                    'lat' => round($lat, 6),
                    'lng' => round($lng, 6),
                    'id' => sprintf('%.5f_%.5f', $lat, $lng),
                ];
                $lng += $lngStep;
            }
            $lat += $latStep;
        }

        if (count($cells) > $maxCells) {
            $step = (int) ceil(count($cells) / $maxCells);
            $cells = array_values(array_filter($cells, static fn (array $_, int $i): bool => $i % $step === 0, ARRAY_FILTER_USE_BOTH));
        }

        return $cells;
    }

    public function clampResolution(int $resolutionMeters): int
    {
        return max(250, min(1000, $resolutionMeters));
    }
}
