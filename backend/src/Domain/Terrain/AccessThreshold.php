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
    public const ALONG_PATH_METERS = 1500;

    /** Metres off the path into the stand, slope-blocked. */
    public const APPROACH_METERS = 150;

    /** Approach (not trails) refuses cells this steep. */
    public const CLIFF_DEGREES = 40.0;

    public const UNREACHABLE = 9999;

    public static function isAccessible(int $accessMeters): bool
    {
        return $accessMeters < self::UNREACHABLE;
    }
}
