<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

enum ForestCover: int
{
    case Open = 0;
    case Undetermined = 1;
    case Broadleaf = 2;
    case Conifer = 3;
    case Mixed = 4;

    /**
     * Genus lists stay explicit rather than exhaustive: what matters is covering the taxa
     * actually tagged around the Chartreuse, Belledonne and Vercors ranges. Anything else
     * falls back to {@see self::Undetermined}, which scores the same for every species and
     * therefore discriminates nothing — so widening these lists directly sharpens the map.
     *
     * @param array<string, string> $tags
     */
    public static function fromOsmTags(array $tags): self
    {
        $haystack = strtolower(implode(' ', [
            $tags['leaf_type'] ?? '',
            $tags['leaf_cycle'] ?? '',
            $tags['wood'] ?? '',
            $tags['trees'] ?? '',
            $tags['species'] ?? '',
            $tags['species:fr'] ?? '',
            $tags['genus'] ?? '',
            $tags['taxon'] ?? '',
            $tags['landcover'] ?? '',
        ]));

        return match (true) {
            str_contains($haystack, 'mixed') => self::Mixed,
            str_contains($haystack, 'needle'),
            str_contains($haystack, 'conifer'),
            str_contains($haystack, 'evergreen'),
            str_contains($haystack, 'abies'),
            str_contains($haystack, 'picea'),
            str_contains($haystack, 'pinus'),
            str_contains($haystack, 'larix'),
            str_contains($haystack, 'pseudotsuga'),
            str_contains($haystack, 'juniperus'),
            str_contains($haystack, 'taxus'),
            str_contains($haystack, 'cedrus'),
            str_contains($haystack, 'sapin'),
            str_contains($haystack, 'épicéa'),
            str_contains($haystack, 'epicea'),
            str_contains($haystack, 'mélèze'),
            str_contains($haystack, 'meleze'),
            str_contains($haystack, 'douglas'),
            str_contains($haystack, 'pin ') => self::Conifer,
            str_contains($haystack, 'broadleaved'),
            str_contains($haystack, 'deciduous'),
            str_contains($haystack, 'fagus'),
            str_contains($haystack, 'quercus'),
            str_contains($haystack, 'castanea'),
            str_contains($haystack, 'fraxinus'),
            str_contains($haystack, 'carpinus'),
            str_contains($haystack, 'betula'),
            str_contains($haystack, 'acer'),
            str_contains($haystack, 'corylus'),
            str_contains($haystack, 'populus'),
            str_contains($haystack, 'salix'),
            str_contains($haystack, 'tilia'),
            str_contains($haystack, 'alnus'),
            str_contains($haystack, 'robinia'),
            str_contains($haystack, 'juglans'),
            str_contains($haystack, 'hêtre'),
            str_contains($haystack, 'hetre'),
            str_contains($haystack, 'chêne'),
            str_contains($haystack, 'chene'),
            str_contains($haystack, 'châtaignier'),
            str_contains($haystack, 'chataignier'),
            str_contains($haystack, 'charme'),
            str_contains($haystack, 'frêne'),
            str_contains($haystack, 'frene'),
            str_contains($haystack, 'bouleau'),
            str_contains($haystack, 'érable'),
            str_contains($haystack, 'erable'),
            str_contains($haystack, 'peuplier') => self::Broadleaf,
            default => self::Undetermined,
        };
    }

    /**
     * IGN BD Forêt V2 vegetation type codes (attribute CODE_TFV). Levels I–II give canopy
     * cover and levels III onward the species composition:
     * FF closed forest, FO open forest, FP poplar plantation, LA heath or grassland.
     *
     * Stands recorded without tree cover — clearcuts, storm or fire damage — are mapped to
     * {@see self::Open}: sporocarp production collapses once the host trees are gone, so
     * treating them as forest would be misleading even though the parcel is forest land.
     *
     * Canopy closure and the dominant host are decoded from the same code by
     * {@see CanopyClosure::fromBdForetCode()} and {@see HostTree::fromBdForetCode()}, then
     * packed with this class into a {@see StandCode}.
     */
    public static function fromBdForetCode(?string $code): self
    {
        $normalised = strtoupper(trim((string) $code));

        if ($normalised === '') {
            return self::Undetermined;
        }

        return match (true) {
            str_starts_with($normalised, 'FF31'),
            str_starts_with($normalised, 'FF32'),
            str_starts_with($normalised, 'FO3') => self::Mixed,
            str_starts_with($normalised, 'FF1'),
            str_starts_with($normalised, 'FO1'),
            str_starts_with($normalised, 'FP') => self::Broadleaf,
            str_starts_with($normalised, 'FF2'),
            str_starts_with($normalised, 'FO2') => self::Conifer,
            str_starts_with($normalised, 'FF0'),
            str_starts_with($normalised, 'FO0'),
            str_starts_with($normalised, 'LA') => self::Open,
            default => self::Undetermined,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Hors forêt',
            self::Undetermined => 'Forêt, essence indéterminée',
            self::Broadleaf => 'Feuillus',
            self::Conifer => 'Conifères',
            self::Mixed => 'Forêt mixte',
        };
    }

    public function isForest(): bool
    {
        return $this !== self::Open;
    }
}
