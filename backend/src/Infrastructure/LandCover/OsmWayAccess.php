<?php

declare(strict_types=1);

namespace App\Infrastructure\LandCover;

/**
 * Classifies OSM tags into "where you can leave the car" vs "where you walk in".
 *
 * Parkable matches the white-fill / dark-casing roads on the standard OSM map
 * (tertiary, unclassified, residential) plus amenity=parking. Tracks and service
 * alleys are walkable only: they are not a place to start the car leg.
 */
final class OsmWayAccess
{
    /** White fill, grey/black casing on OSM Carto (zoom ≥ 13). */
    private const PARK_HIGHWAYS = [
        'tertiary',
        'tertiary_link',
        'unclassified',
        'residential',
        'living_street',
        'road',
    ];

    private const PATH_HIGHWAYS = [
        'track',
        'path',
        'footway',
        'bridleway',
        'cycleway',
        'service',
        'primary',
        'primary_link',
        'secondary',
        'secondary_link',
    ];

    /** @param array<string, mixed> $tags */
    public static function isParkable(array $tags): bool
    {
        if (self::isClosedToCars($tags)) {
            return false;
        }
        if (($tags['amenity'] ?? null) === 'parking') {
            return true;
        }

        return \in_array((string) ($tags['highway'] ?? ''), self::PARK_HIGHWAYS, true);
    }

    /** @param array<string, mixed> $tags */
    public static function isWalkable(array $tags): bool
    {
        if (($tags['amenity'] ?? null) === 'parking') {
            return true;
        }

        $highway = (string) ($tags['highway'] ?? '');

        return \in_array($highway, self::PARK_HIGHWAYS, true)
            || \in_array($highway, self::PATH_HIGHWAYS, true);
    }

    /** @param array<string, mixed> $tags */
    private static function isClosedToCars(array $tags): bool
    {
        return ($tags['motor_vehicle'] ?? '') === 'no' || ($tags['access'] ?? '') === 'no';
    }
}
