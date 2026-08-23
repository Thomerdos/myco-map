<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

/**
 * Dominant tree of a stand, which is the single most useful thing to know: every species
 * tracked here except the morel is ectomycorrhizal, so the host determines whether the
 * fungus can be present at all. Broadleaf/conifer is a crude proxy for that — a beech
 * stand and a chestnut stand are both "broadleaf" yet hold very different fungi.
 *
 * Filled from IGN BD Forêt V2, whose vegetation type codes name the dominant species for
 * pure stands. OpenStreetMap only rarely allows it, hence {@see self::Unknown}.
 */
enum HostTree: int
{
    case Unknown = 0;
    case Beech = 1;
    case Oak = 2;
    case Chestnut = 3;
    case SpruceFir = 4;
    case Pine = 5;
    case Larch = 6;
    case Douglas = 7;
    case Poplar = 8;
    case Robinia = 9;
    case OtherBroadleaf = 10;
    case OtherConifer = 11;

    /**
     * BD Forêt V2 `CODE_TFV`. Levels III and IV carry the dominant species for pure stands;
     * mixtures resolve to the generic broadleaf or conifer entries because no single host
     * dominates them.
     */
    public static function fromBdForetCode(?string $code): self
    {
        $normalised = strtoupper(trim((string) $code));

        if ($normalised === '') {
            return self::Unknown;
        }

        return match (true) {
            str_starts_with($normalised, 'FF1-09') => self::Beech,
            str_starts_with($normalised, 'FF1G01'),
            str_starts_with($normalised, 'FF1G06') => self::Oak,
            str_starts_with($normalised, 'FF1-10') => self::Chestnut,
            str_starts_with($normalised, 'FF1-14') => self::Robinia,
            str_starts_with($normalised, 'FF2G61') => self::SpruceFir,
            str_starts_with($normalised, 'FF2-63') => self::Larch,
            str_starts_with($normalised, 'FF2-64') => self::Douglas,
            str_starts_with($normalised, 'FF2-51'),
            str_starts_with($normalised, 'FF2-52'),
            str_starts_with($normalised, 'FF2-57'),
            str_starts_with($normalised, 'FF2-80'),
            str_starts_with($normalised, 'FF2-81'),
            str_starts_with($normalised, 'FF2G53'),
            str_starts_with($normalised, 'FF2G58') => self::Pine,
            str_starts_with($normalised, 'FP') => self::Poplar,
            str_starts_with($normalised, 'FF1'),
            str_starts_with($normalised, 'FO1') => self::OtherBroadleaf,
            str_starts_with($normalised, 'FF2'),
            str_starts_with($normalised, 'FO2') => self::OtherConifer,
            default => self::Unknown,
        };
    }

    /** @param array<string, string> $tags */
    public static function fromOsmTags(array $tags): self
    {
        $haystack = strtolower(implode(' ', [
            $tags['species'] ?? '',
            $tags['species:fr'] ?? '',
            $tags['genus'] ?? '',
            $tags['taxon'] ?? '',
        ]));

        if ($haystack === '' || trim($haystack) === '') {
            return self::Unknown;
        }

        return match (true) {
            str_contains($haystack, 'fagus'), str_contains($haystack, 'hêtre'), str_contains($haystack, 'hetre') => self::Beech,
            str_contains($haystack, 'quercus'), str_contains($haystack, 'chêne'), str_contains($haystack, 'chene') => self::Oak,
            str_contains($haystack, 'castanea'), str_contains($haystack, 'châtaignier'), str_contains($haystack, 'chataignier') => self::Chestnut,
            str_contains($haystack, 'picea'), str_contains($haystack, 'abies'), str_contains($haystack, 'épicéa'),
            str_contains($haystack, 'epicea'), str_contains($haystack, 'sapin') => self::SpruceFir,
            str_contains($haystack, 'larix'), str_contains($haystack, 'mélèze'), str_contains($haystack, 'meleze') => self::Larch,
            str_contains($haystack, 'pseudotsuga'), str_contains($haystack, 'douglas') => self::Douglas,
            str_contains($haystack, 'pinus'), str_contains($haystack, 'pin ') => self::Pine,
            str_contains($haystack, 'populus'), str_contains($haystack, 'peuplier') => self::Poplar,
            str_contains($haystack, 'robinia'), str_contains($haystack, 'robinier') => self::Robinia,
            default => self::Unknown,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Essence dominante inconnue',
            self::Beech => 'Hêtre',
            self::Oak => 'Chêne',
            self::Chestnut => 'Châtaignier',
            self::SpruceFir => 'Sapin ou épicéa',
            self::Pine => 'Pin',
            self::Larch => 'Mélèze',
            self::Douglas => 'Douglas',
            self::Poplar => 'Peuplier',
            self::Robinia => 'Robinier',
            self::OtherBroadleaf => 'Autre feuillu',
            self::OtherConifer => 'Autre conifère',
        };
    }

    public function isKnown(): bool
    {
        return $this !== self::Unknown;
    }
}
