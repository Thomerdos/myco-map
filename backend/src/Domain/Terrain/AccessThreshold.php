<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

/**
 * Walk-from-parking budget used to paint the access mask and to hide unreachable
 * cells on the potential overlay. Not a score weight.
 */
final class AccessThreshold
{
    /** Metres along the OSM path network from a parkable way. */
    public const ALONG_PATH_METERS = 2000;

    /** Metres off the path into the stand, slope-blocked. */
    public const APPROACH_METERS = 500;

    /** Approach (not trails) refuses cells this steep. */
    public const CLIFF_DEGREES = 40.0;

    /** Flat-ground equivalent used to turn slope-weighted metres into minutes. */
    public const WALK_METERS_PER_HOUR = 4000;

    public const UNREACHABLE = 9999;

    public static function isAccessible(int $accessMeters): bool
    {
        return $accessMeters < self::UNREACHABLE;
    }

    public static function walkingMinutes(int $meters): int
    {
        if ($meters <= 0 || $meters >= self::UNREACHABLE) {
            return 0;
        }

        return max(1, (int) round($meters / self::WALK_METERS_PER_HOUR * 60));
    }
}
