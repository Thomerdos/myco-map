<?php

declare(strict_types=1);

namespace App\Infrastructure\LandCover;

/**
 * Classifies OSM tags into "where you can leave the car" vs "where you walk in".
 * Forest tracks without a tracktype stay parkable: the tag is often missing on DFCI pistes.
 */
final class OsmWayAccess
{
    private const PARK_HIGHWAYS = [
        'primary',
        'secondary',
        'tertiary',
        'unclassified',
        'residential',
        'living_street',
        'service',
    ];

    private const PATH_HIGHWAYS = [
        'track',
        'path',
        'footway',
        'bridleway',
        'cycleway',
    ];

    /** @param array<string, mixed> $tags */
    public static function isParkable(array $tags): bool
    {
        if (($tags['amenity'] ?? null) === 'parking') {
            return true;
        }

        $highway = (string) ($tags['highway'] ?? '');
        if (\in_array($highway, self::PARK_HIGHWAYS, true)) {
            return true;
        }
        if ($highway !== 'track') {
            return false;
        }
        if (($tags['motor_vehicle'] ?? '') === 'no' || ($tags['access'] ?? '') === 'no') {
            return false;
        }

        $tracktype = (string) ($tags['tracktype'] ?? '');

        return $tracktype !== 'grade4' && $tracktype !== 'grade5';
    }

    /** @param array<string, mixed> $tags */
    public static function isWalkable(array $tags): bool
    {
        $highway = (string) ($tags['highway'] ?? '');

        return \in_array($highway, self::PATH_HIGHWAYS, true);
    }
}
