<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Precomputation\PrecomputationProgress;
use App\Application\Precomputation\PrecomputeTerrain;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:precompute',
    description: 'Précalcule relief, couvert forestier et hydrographie de la zone d\'étude',
)]
final class PrecomputeCommand extends Command
{
    public function __construct(private readonly PrecomputeTerrain $precomputeTerrain)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '2G');

        $io = new SymfonyStyle($input, $output);
        $io->title(sprintf('Précalcul — %s', $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'unknown'));

        try {
            $report = ($this->precomputeTerrain)(new class($io) implements PrecomputationProgress {
                public function __construct(private readonly SymfonyStyle $io)
                {
                }

                public function stageStarted(string $stage): void
                {
                    $this->io->writeln(sprintf('<info>▸</info> %s', $stage));
                }

                public function stageAdvanced(string $stage, int $done, int $total): void
                {
                    $this->io->writeln($total > 0
                        ? sprintf('   %d / %d', $done, $total)
                        : sprintf('   %d éléments', $done));
                }

                public function stageFinished(string $stage): void
                {
                }
            });
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s mailles de %d m (%d × %d) en %.0f s (pic mémoire PHP %.0f Mo) — %d tuiles de relief, %d polygones forestiers, %d formations géologiques, %d éléments hydro, %d voies d\'accès, %d mailles TCD, %d mailles LIDAR, %d mailles pH',
            number_format($report->cells, 0, ',', ' '),
            $report->cellSizeMeters,
            $report->columns,
            $report->rows,
            $report->durationSeconds,
            memory_get_peak_usage(true) / 1_048_576,
            $report->elevationTiles,
            $report->forestPolygons,
            $report->geologyPolygons,
            $report->waterFeatures,
            $report->accessWays,
            $report->canopyCoverCells,
            $report->canopyHeightCells,
            $report->soilPhCells,
        ));

        if ($report->canopyCoverCells === 0) {
            $io->note(
                'TCD Copernicus absent : densité en repli FO/FF (ou LIDAR si présent). Téléchargez '
                .'avec ./dev.sh tcd puis relancez le précalcul.'
            );
        }

        if ($report->canopyHeightCells === 0) {
            $io->note(
                'CHM LIDAR HD absent : densité TCD / FO-FF. Placez un CHM (MNS−MNT) puis '
                .'./dev.sh lidar … et relancez le précalcul.'
            );
        }

        if ($report->soilPhCells === 0) {
            $io->note(
                'pH EcoDataCube absent : critère pH en repli Charm-50. Lancez ./dev.sh soilph '
                .'puis relancez le précalcul.'
            );
        }

        if (!$report->isComplete()) {
            $io->warning(sprintf(
                '%d tuile(s) OpenStreetMap n\'ont pas pu être téléchargées. Relancez la commande '
                . 'plus tard pour compléter : les tuiles déjà obtenues sont en cache.',
                $report->unavailableChunks,
            ));
        }

        return Command::SUCCESS;
    }
}
