<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

final readonly class SuitabilityScore
{
    /** @param list<CriterionScore> $breakdown */
    public function __construct(
        public float $value,
        public SuitabilityLevel $level,
        public array $breakdown,
        public bool $inSeason,
    ) {
    }

    /**
     * Criteria ordered by how strongly they push the score up or down.
     *
     * @return list<CriterionScore>
     */
    public function drivers(int $limit = 5): array
    {
        $sorted = $this->breakdown;
        usort(
            $sorted,
            static fn (CriterionScore $a, CriterionScore $b): int => abs($b->influence()) <=> abs($a->influence())
        );

        return \array_slice($sorted, 0, $limit);
    }
}
