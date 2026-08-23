<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

enum EdgeAffinity: string
{
    case Edge = 'edge';
    case Interior = 'interior';
    case Indifferent = 'indifferent';

    /**
     * @param int $edgeDistance positive inside forest, negative outside
     */
    public function suitability(int $edgeDistance): float
    {
        return match ($this) {
            self::Edge => $this->edgeSeeking($edgeDistance),
            self::Interior => $this->interiorSeeking($edgeDistance),
            self::Indifferent => $edgeDistance >= 0 ? 1.0 : 0.35,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Edge => 'Recherche les lisières et bordures',
            self::Interior => 'Préfère l\'intérieur des massifs boisés',
            self::Indifferent => 'Peu sensible à la position dans le massif',
        };
    }

    private function edgeSeeking(int $edgeDistance): float
    {
        $distance = abs($edgeDistance);
        if ($distance <= 120) {
            return 1.0;
        }
        if ($edgeDistance < 0) {
            return max(0.0, 1.0 - ($distance - 120) / 400);
        }

        return max(0.3, 1.0 - ($distance - 120) / 600);
    }

    private function interiorSeeking(int $edgeDistance): float
    {
        if ($edgeDistance < 0) {
            return 0.05;
        }
        if ($edgeDistance >= 200) {
            return 1.0;
        }

        return 0.45 + 0.55 * ($edgeDistance / 200);
    }
}
