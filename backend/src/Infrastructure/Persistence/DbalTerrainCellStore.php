<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Geo\BoundingBox;
use App\Domain\Geo\Coordinates;
use App\Domain\Geo\Grid;
use App\Domain\Geo\GridWindow;
use App\Domain\Terrain\StandCode;
use App\Domain\Terrain\TerrainCellStore;
use App\Domain\Terrain\TerrainProfile;
use Doctrine\DBAL\Connection;

final class DbalTerrainCellStore implements TerrainCellStore
{
    private const INSERT_CHUNK = 500;

    private ?Grid $cachedGrid = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function prepareStorage(): void
    {
        $this->connection->executeStatement('PRAGMA journal_mode = WAL');
        $this->connection->executeStatement('PRAGMA synchronous = OFF');

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS terrain_cell (
                row_index INTEGER NOT NULL,
                column_index INTEGER NOT NULL,
                latitude REAL NOT NULL,
                longitude REAL NOT NULL,
                elevation INTEGER NOT NULL,
                slope REAL NOT NULL,
                aspect REAL NOT NULL,
                curvature REAL NOT NULL,
                cover INTEGER NOT NULL, -- packed StandCode (cover + host + canopy); 0-4 still valid
                edge_distance INTEGER NOT NULL,
                water_distance INTEGER NOT NULL,
                PRIMARY KEY (row_index, column_index)
            ) WITHOUT ROWID
            SQL);

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS grid_definition (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                south REAL NOT NULL,
                west REAL NOT NULL,
                north REAL NOT NULL,
                east REAL NOT NULL,
                cell_size INTEGER NOT NULL,
                columns_count INTEGER NOT NULL,
                rows_count INTEGER NOT NULL,
                cell_count INTEGER NOT NULL,
                computed_at TEXT NOT NULL
            )
            SQL);
    }

    public function replaceAll(Grid $grid, \Traversable $cells): int
    {
        $this->connection->executeStatement('DELETE FROM terrain_cell');
        $this->connection->beginTransaction();

        $written = 0;
        $buffer = [];

        try {
            foreach ($cells as $cell) {
                /** @var TerrainProfile $profile */
                $profile = $cell['profile'];

                $buffer[] = [
                    $cell['row'],
                    $cell['column'],
                    $profile->coordinates->latitude,
                    $profile->coordinates->longitude,
                    $profile->elevationMeters,
                    $profile->slopeDegrees,
                    $profile->aspectDegrees,
                    $profile->curvature,
                    $profile->standCode(),
                    $profile->edgeDistanceMeters,
                    $profile->waterDistanceMeters,
                ];

                if (\count($buffer) >= self::INSERT_CHUNK) {
                    $this->flush($buffer);
                    $written += \count($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $this->flush($buffer);
                $written += \count($buffer);
            }

            $this->connection->executeStatement('DELETE FROM grid_definition');
            $this->connection->executeStatement(
                'INSERT INTO grid_definition (id, south, west, north, east, cell_size, columns_count, rows_count, cell_count, computed_at)
                 VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $grid->bounds->south,
                    $grid->bounds->west,
                    $grid->bounds->north,
                    $grid->bounds->east,
                    $grid->cellSizeMeters,
                    $grid->columns,
                    $grid->rows,
                    $written,
                    (new \DateTimeImmutable())->format(\DATE_ATOM),
                ],
            );

            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }

        $this->cachedGrid = null;

        return $written;
    }

    public function storedGrid(): ?Grid
    {
        if ($this->cachedGrid !== null) {
            return $this->cachedGrid;
        }

        try {
            $row = $this->connection->fetchAssociative('SELECT * FROM grid_definition WHERE id = 1');
        } catch (\Throwable) {
            return null;
        }

        if ($row === false) {
            return null;
        }

        return $this->cachedGrid = new Grid(
            new BoundingBox(
                (float) $row['south'],
                (float) $row['west'],
                (float) $row['north'],
                (float) $row['east'],
            ),
            (int) $row['cell_size'],
        );
    }

    public function isEmpty(): bool
    {
        try {
            return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM terrain_cell LIMIT 1') === 0;
        } catch (\Throwable) {
            return true;
        }
    }

    public function readWindow(GridWindow $window): iterable
    {
        $sql = 'SELECT column_index, row_index, latitude, longitude, elevation, slope, aspect, curvature,
                       cover, edge_distance, water_distance
                FROM terrain_cell
                WHERE row_index BETWEEN :firstRow AND :lastRow
                  AND column_index BETWEEN :firstColumn AND :lastColumn';

        $parameters = [
            'firstRow' => $window->firstRow,
            'lastRow' => $window->lastRow,
            'firstColumn' => $window->firstColumn,
            'lastColumn' => $window->lastColumn,
        ];

        if ($window->step > 1) {
            $sql .= ' AND ((row_index - :firstRow) % :step) = 0
                      AND ((column_index - :firstColumn) % :step) = 0';
            $parameters['step'] = $window->step;
        }

        $result = $this->connection->executeQuery($sql, $parameters);

        while (($row = $result->fetchAssociative()) !== false) {
            yield [
                'column' => (int) $row['column_index'],
                'row' => (int) $row['row_index'],
                'profile' => $this->hydrate($row),
            ];
        }
    }

    public function findNearest(Coordinates $point): ?TerrainProfile
    {
        $row = $this->connection->fetchAssociative(
            'SELECT column_index, row_index, latitude, longitude, elevation, slope, aspect, curvature,
                    cover, edge_distance, water_distance
             FROM terrain_cell
             WHERE latitude BETWEEN :latMin AND :latMax
               AND longitude BETWEEN :lngMin AND :lngMax
             ORDER BY (latitude - :lat) * (latitude - :lat)
                    + (longitude - :lng) * (longitude - :lng)
             LIMIT 1',
            [
                'lat' => $point->latitude,
                'lng' => $point->longitude,
                'latMin' => $point->latitude - 0.006,
                'latMax' => $point->latitude + 0.006,
                'lngMin' => $point->longitude - 0.009,
                'lngMax' => $point->longitude + 0.009,
            ],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    /** @param list<array<int, float|int>> $buffer */
    private function flush(array $buffer): void
    {
        $placeholders = implode(',', array_fill(0, \count($buffer), '(?,?,?,?,?,?,?,?,?,?,?)'));
        $parameters = [];

        foreach ($buffer as $values) {
            foreach ($values as $value) {
                $parameters[] = $value;
            }
        }

        $this->connection->executeStatement(
            'INSERT OR REPLACE INTO terrain_cell
             (row_index, column_index, latitude, longitude, elevation, slope, aspect, curvature, cover, edge_distance, water_distance)
             VALUES ' . $placeholders,
            $parameters,
        );
    }

    /**
     * The `cover` column stores a {@see StandCode}: 3 bits of ForestCover, then host tree,
     * then canopy. Archives written before packing only stored 0–4, which still unpacks as
     * that cover class plus unknown host and canopy.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): TerrainProfile
    {
        $packed = (int) $row['cover'];

        return new TerrainProfile(
            coordinates: new Coordinates((float) $row['latitude'], (float) $row['longitude']),
            elevationMeters: (int) $row['elevation'],
            slopeDegrees: (float) $row['slope'],
            aspectDegrees: (float) $row['aspect'],
            curvature: (float) $row['curvature'],
            cover: StandCode::cover($packed),
            edgeDistanceMeters: (int) $row['edge_distance'],
            waterDistanceMeters: (int) $row['water_distance'],
            hostTree: StandCode::host($packed),
            canopy: StandCode::canopy($packed),
        );
    }
}
