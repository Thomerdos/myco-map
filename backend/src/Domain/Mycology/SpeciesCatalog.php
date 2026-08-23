<?php

declare(strict_types=1);

namespace App\Domain\Mycology;

interface SpeciesCatalog
{
    /** @return list<Species> */
    public function all(): array;

    public function get(string $id): Species;

    public function has(string $id): bool;
}
