<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

final readonly class CriterionScore
{
    public function __construct(
        public Criterion $criterion,
        public float $value,
        public string $explanation,
    ) {
    }

    public function weighted(): float
    {
        return $this->value * $this->criterion->weight();
    }

    /**
     * How much this criterion pulls the overall score away from neutral, used to
     * surface the handful of factors worth showing to the user.
     */
    public function influence(): float
    {
        return ($this->value - 50.0) * $this->criterion->weight();
    }

    /** @return array<string, float|string> */
    public function toArray(): array
    {
        return [
            'criterion' => $this->criterion->value,
            'label' => $this->criterion->label(),
            'value' => round($this->value, 1),
            'weight' => $this->criterion->weight(),
            'explanation' => $this->explanation,
        ];
    }
}
