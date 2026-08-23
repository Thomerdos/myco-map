<?php

declare(strict_types=1);

namespace App\Domain\Geo;

final readonly class GridWindow
{
    public function __construct(
        public int $firstColumn,
        public int $lastColumn,
        public int $firstRow,
        public int $lastRow,
        public int $step,
    ) {
    }

    public function columns(): int
    {
        return (int) ceil(($this->lastColumn - $this->firstColumn + 1) / $this->step);
    }

    public function rows(): int
    {
        return (int) ceil(($this->lastRow - $this->firstRow + 1) / $this->step);
    }

    public function localColumn(int $column): int
    {
        return intdiv($column - $this->firstColumn, $this->step);
    }

    public function localRow(int $row): int
    {
        return intdiv($row - $this->firstRow, $this->step);
    }

    public function cellCount(): int
    {
        return $this->columns() * $this->rows();
    }
}
