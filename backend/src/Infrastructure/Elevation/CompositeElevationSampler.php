<?php

declare(strict_types=1);

namespace App\Infrastructure\Elevation;

use App\Domain\Geo\BoundingBox;
use App\Domain\Geo\Coordinates;
use App\Domain\Terrain\ElevationSampler;
use Psr\Log\LoggerInterface;

/**
 * Prefers a local RGE ALTI mosaic when present, otherwise Terrarium tiles.
 */
final class CompositeElevationSampler implements ElevationSampler
{
    public function __construct(
        private readonly ElevationSampler $preferred,
        private readonly ElevationSampler $fallback,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function prepare(BoundingBox $bounds): int
    {
        if ($this->preferred instanceof RgeAltiElevation && $this->preferred->isAvailable()) {
            $this->logger->info('Relief depuis RGE ALTI (MNT IGN)');

            return $this->preferred->prepare($bounds);
        }

        $this->logger->info('Relief depuis Terrarium (repli)');

        return $this->fallback->prepare($bounds);
    }

    public function elevationAt(Coordinates $point): ?float
    {
        if ($this->preferred instanceof RgeAltiElevation && $this->preferred->isAvailable()) {
            return $this->preferred->elevationAt($point);
        }

        return $this->fallback->elevationAt($point);
    }

    public function elevationAtLatLng(float $latitude, float $longitude): ?float
    {
        if ($this->preferred instanceof RgeAltiElevation && $this->preferred->isAvailable()) {
            return $this->preferred->elevationAtLatLng($latitude, $longitude);
        }

        return $this->fallback->elevationAtLatLng($latitude, $longitude);
    }
}
