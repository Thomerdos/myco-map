<?php

declare(strict_types=1);

namespace App\Domain\Terrain;

use App\Domain\Geo\Coordinates;

/**
 * Reconstructed walk from a parkable OSM way to a clicked cell. Metres are the
 * same slope-weighted cost stored in access_distance.
 */
final readonly class AccessWalk
{
    /**
     * @param list<array{lat: float, lng: float}> $coordinates
     */
    public function __construct(
        public bool $reachable,
        public int $meters,
        public int $minutes,
        public int $alongMeters,
        public int $approachMeters,
        public ?Coordinates $start,
        public array $coordinates,
        public int $approachFromIndex,
    ) {
    }

    public static function unreachable(): self
    {
        return new self(
            reachable: false,
            meters: AccessThreshold::UNREACHABLE,
            minutes: 0,
            alongMeters: 0,
            approachMeters: 0,
            start: null,
            coordinates: [],
            approachFromIndex: 0,
        );
    }

    public static function fromMeters(int $meters): self
    {
        if (!AccessThreshold::isAccessible($meters)) {
            return self::unreachable();
        }

        return new self(
            reachable: true,
            meters: $meters,
            minutes: AccessThreshold::walkingMinutes($meters),
            alongMeters: $meters,
            approachMeters: 0,
            start: null,
            coordinates: [],
            approachFromIndex: 0,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'reachable' => $this->reachable,
            'meters' => $this->meters,
            'minutes' => $this->minutes,
            'alongMeters' => $this->alongMeters,
            'approachMeters' => $this->approachMeters,
            'start' => $this->start === null ? null : [
                'lat' => round($this->start->latitude, 5),
                'lng' => round($this->start->longitude, 5),
            ],
            'coordinates' => $this->coordinates,
            'approachFromIndex' => $this->approachFromIndex,
        ];
    }
}
