<?php

declare(strict_types=1);

namespace App\Infrastructure\LandCover;

/**
 * Thrown from the cache callback so Symfony does not persist a failed Overpass tile.
 */
final class OverpassUnavailable extends \RuntimeException
{
}
