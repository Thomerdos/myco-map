<?php

declare(strict_types=1);

namespace App\Application\Precomputation;

interface PrecomputationProgress
{
    public function stageStarted(string $stage): void;

    public function stageAdvanced(string $stage, int $done, int $total): void;

    public function stageFinished(string $stage): void;
}
