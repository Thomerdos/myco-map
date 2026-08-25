<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

/**
 * How closed the tree canopy is. Stand density is the stand variable most consistently
 * correlated with mushroom yields — production peaks at intermediate basal area and falls
 * off in stands that are too sparse — and it also drives the understorey microclimate that
 * fruitbodies need.
 *
 * BD Forêt V2 encodes it in the first two characters of the vegetation type code: `FF`
 * closed forest is above 40 % tree cover, `FO` open forest between 10 and 40 %.
 */
enum CanopyClosure: int
{
    case Unknown = 0;
    case Open = 1;
    case Closed = 2;

    public static function fromBdForetCode(?string $code): self
    {
        $normalised = strtoupper(trim((string) $code));

        return match (true) {
            $normalised === '' => self::Unknown,
            str_starts_with($normalised, 'FF0'),
            str_starts_with($normalised, 'FO'),
            str_starts_with($normalised, 'LA') => self::Open,
            str_starts_with($normalised, 'FF'),
            str_starts_with($normalised, 'FP') => self::Closed,
            default => self::Unknown,
        };
    }

    /**
     * OpenStreetMap has no canopy density tag in general use. Scrub is the one case that can
     * be read confidently, so everything else stays unknown rather than being guessed.
     *
     * @param array<string, string> $tags
     */
    public static function fromOsmTags(array $tags): self
    {
        return ($tags['natural'] ?? '') === 'scrub' ? self::Open : self::Unknown;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Densité inconnue',
            self::Open => 'Couvert ouvert (10 à 40 %)',
            self::Closed => 'Couvert fermé (plus de 40 %)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Unknown => 'Densité inconnue',
            self::Open => 'Ouvert',
            self::Closed => 'Fermé',
        };
    }

    public function isKnown(): bool
    {
        return $this !== self::Unknown;
    }

    /**
     * Mid-bin percent used when Copernicus TCD is missing. FO is 10–40 %, FF is > 40 %.
     */
    public function proxyCoverPercent(): ?int
    {
        return match ($this) {
            self::Open => 25,
            self::Closed => 70,
            self::Unknown => null,
        };
    }
}
