<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

/**
 * Packs the three stand attributes into one integer so the rasterizer fills a single grid
 * instead of three, and the precomputation keeps one array in memory instead of three.
 *
 * Layout, low bits first: 3 bits of {@see ForestCover}, 4 bits of {@see HostTree}, 2 bits
 * of {@see CanopyClosure}.
 */
final readonly class StandCode
{
    private const COVER_MASK = 0b111;
    private const HOST_SHIFT = 3;
    private const HOST_MASK = 0b1111;
    private const CANOPY_SHIFT = 7;
    private const CANOPY_MASK = 0b11;

    public static function pack(ForestCover $cover, HostTree $host, CanopyClosure $canopy): int
    {
        return $cover->value
            | ($host->value << self::HOST_SHIFT)
            | ($canopy->value << self::CANOPY_SHIFT);
    }

    public static function cover(int $packed): ForestCover
    {
        return ForestCover::from($packed & self::COVER_MASK);
    }

    public static function host(int $packed): HostTree
    {
        return HostTree::from(($packed >> self::HOST_SHIFT) & self::HOST_MASK);
    }

    public static function canopy(int $packed): CanopyClosure
    {
        return CanopyClosure::from(($packed >> self::CANOPY_SHIFT) & self::CANOPY_MASK);
    }

    public static function isOpenGround(int $packed): bool
    {
        return ($packed & self::COVER_MASK) === ForestCover::Open->value;
    }
}
