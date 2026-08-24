<?php

declare(strict_types=1);

namespace App\Application\Cartography;

use App\Domain\Cartography\MapLayer;
use App\Domain\Geo\BoundingBox;
use App\Domain\Mycology\ScoringMode;

final readonly class LayerGridQuery
{
    public function __construct(
        public BoundingBox $viewport,
        public MapLayer $layer,
        public string $speciesId,
        public int $maxCells,
        public \DateTimeImmutable $date,
        public ScoringMode $scoringMode = ScoringMode::Moment,
    ) {
    }
}
