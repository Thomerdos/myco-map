<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

/**
 * Sporocarp production drops sharply in the edge band — wind and sun destabilise soil
 * temperature and moisture there — with reported reductions around 65 % in fruiting
 * richness compared with interior forest (Rianhard et al. 2025, Luoma et al. 2004). So
 * even species with no particular preference carry an edge penalty, and {@see self::Edge}
 * is reserved for species tied to disturbed ground rather than to closed stands.
 */
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
            self::Indifferent => $this->neutral($edgeDistance),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Edge => 'Recherche les bordures et les sols remaniés',
            self::Interior => 'Préfère l\'intérieur des massifs boisés',
            self::Indifferent => 'Sans préférence marquée, mais pénalisée en pleine lisière',
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

        return 0.3 + 0.7 * ($edgeDistance / 200);
    }

    /**
     * No stand-scale preference still means the edge band itself fruits less, so the
     * curve rises from the boundary to a plateau inside the stand.
     */
    private function neutral(int $edgeDistance): float
    {
        if ($edgeDistance < 0) {
            return 0.35;
        }
        if ($edgeDistance >= 120) {
            return 1.0;
        }

        return 0.6 + 0.4 * ($edgeDistance / 120);
    }
}
