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

    /** @param array<string, string> $tags */
    public static function fromOsmTags(array $tags): self
    {
        $haystack = strtolower(implode(' ', [
            $tags['leaf_type'] ?? '',
            $tags['wood'] ?? '',
            $tags['trees'] ?? '',
            $tags['species'] ?? '',
            $tags['genus'] ?? '',
        ]));

        return match (true) {
            str_contains($haystack, 'mixed') => self::Mixed,
            str_contains($haystack, 'needle'),
            str_contains($haystack, 'conifer'),
            str_contains($haystack, 'abies'),
            str_contains($haystack, 'picea'),
            str_contains($haystack, 'pinus'),
            str_contains($haystack, 'larix') => self::Conifer,
            str_contains($haystack, 'broadleaved'),
            str_contains($haystack, 'deciduous'),
            str_contains($haystack, 'fagus'),
            str_contains($haystack, 'quercus'),
            str_contains($haystack, 'castanea'),
            str_contains($haystack, 'fraxinus') => self::Broadleaf,
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
