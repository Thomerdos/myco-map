<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

final readonly class HarvestWindow
{
    public function __construct(
        public string $start,
        public string $end,
        public bool $isPeak,
        public string $label,
    ) {
    }

    public function covers(\DateTimeImmutable $date): bool
    {
        $year = (int) $date->format('Y');
        $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', sprintf('%d-%s 00:00:00', $year, $this->start));
        $end = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', sprintf('%d-%s 23:59:59', $year, $this->end));

        if ($start === false || $end === false) {
            return false;
        }

        if ($start > $end) {
            return $date >= $start || $date <= $end;
        }

        return $date >= $start && $date <= $end;
    }

    /** @return array<string, bool|string> */
    public function toArray(): array
    {
        return [
            'start' => $this->start,
            'end' => $this->end,
            'peak' => $this->isPeak,
            'label' => $this->label,
        ];
    }
}
