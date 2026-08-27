<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Geo\BoundingBox;
use App\Domain\Geo\Coordinates;
use App\Domain\Geo\Grid;
use App\Domain\Geo\GridWindow;
use App\Domain\Terrain\AccessThreshold;
use App\Domain\Terrain\StandCode;
use App\Domain\Terrain\Substrate;
use App\Domain\Terrain\TerrainCellStore;
use App\Domain\Terrain\TerrainProfile;
use Doctrine\DBAL\Connection;

final class DbalTerrainCellStore implements TerrainCellStore
{
    /** Multi-row INSERT size. 1000 × 19 binds stays under typical SQLite var limits. */
    private const INSERT_CHUNK = 1000;

    private const COLUMNS = 19;

    private ?Grid $cachedGrid = null;
    private ?bool $hasAccessColumn = null;
    private ?bool $hasCanopyCoverColumn = null;
    private ?bool $hasCanopyHeightColumn = null;
    private ?bool $hasCanopyGapColumn = null;
    private ?bool $hasSoilPhColumn = null;
    private ?bool $hasNetworkColumns = null;

    /** @var array<int, string> */
    private array $placeholderCache = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    private bool $readPragmasApplied = false;

    private function ensureReadPragmas(): void
    {
        if ($this->readPragmasApplied) {
            return;
        }

        try {
            $this->connection->executeStatement('PRAGMA query_only = ON');
            $this->connection->executeStatement('PRAGMA temp_store = MEMORY');
            $this->connection->executeStatement('PRAGMA cache_size = -65536'); // ~64 MiB
            $this->connection->executeStatement('PRAGMA mmap_size = 268435456');
        } catch (\Throwable) {
            // Older / locked connections may reject some pragmas; reads still work.
        }

        $this->readPragmasApplied = true;
    }

    public function prepareStorage(): void
    {
        $this->readPragmasApplied = false;
        $this->connection->executeStatement('PRAGMA journal_mode = WAL');
        $this->connection->executeStatement('PRAGMA synchronous = OFF');
        $this->connection->executeStatement('PRAGMA temp_store = MEMORY');
        $this->connection->executeStatement('PRAGMA cache_size = -200000'); // ~200 MiB
        $this->connection->executeStatement('PRAGMA mmap_size = 268435456');
        $this->connection->executeStatement('PRAGMA locking_mode = EXCLUSIVE');

        // Recreate so schema upgrades (geology column) apply on every precompute.
        $this->connection->executeStatement('DROP TABLE IF EXISTS terrain_cell');

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE terrain_cell (
                row_index INTEGER NOT NULL,
                column_index INTEGER NOT NULL,
                latitude REAL NOT NULL,
                longitude REAL NOT NULL,
                elevation INTEGER NOT NULL,
                slope REAL NOT NULL,
                aspect REAL NOT NULL,
                curvature REAL NOT NULL,
                cover INTEGER NOT NULL,
                edge_distance INTEGER NOT NULL,
                water_distance INTEGER NOT NULL,
                geology INTEGER NOT NULL DEFAULT 0,
                access_distance INTEGER NOT NULL DEFAULT 9999,
                canopy_cover INTEGER,
                canopy_height INTEGER,
                canopy_gap INTEGER,
                soil_ph REAL,
                park INTEGER NOT NULL DEFAULT 0,
                path INTEGER NOT NULL DEFAULT 0,
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
                $buffer[] = $cell;
                if (\count($buffer) >= self::INSERT_CHUNK) {
                    $this->flushChunk($buffer);
                    $written += \count($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $this->flushChunk($buffer);
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
        } finally {
            $this->connection->executeStatement('PRAGMA locking_mode = NORMAL');
        }

        $this->cachedGrid = null;
        $this->hasAccessColumn = true;
        $this->hasCanopyCoverColumn = true;
        $this->hasCanopyHeightColumn = true;
        $this->hasCanopyGapColumn = true;
        $this->hasSoilPhColumn = true;
        $this->hasNetworkColumns = true;

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
        $this->ensureReadPragmas();

        $sql = 'SELECT column_index, row_index, latitude, longitude, elevation, slope, aspect, curvature,
                       cover, edge_distance, water_distance, geology'.$this->accessSelect().$this->canopyCoverSelect().$this->canopyHeightSelect().$this->canopyGapSelect().$this->soilPhSelect().$this->networkSelect().'
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

        $rows = $this->connection->fetchAllAssociative($sql, $parameters);

        foreach ($rows as $row) {
            yield [
                'column' => (int) $row['column_index'],
                'row' => (int) $row['row_index'],
                'profile' => $this->hydrate($row),
                'park' => (int) ($row['park'] ?? 0),
                'path' => (int) ($row['path'] ?? 0),
            ];
        }
    }

    public function readAccessMask(GridWindow $window): iterable
    {
        $this->ensureReadPragmas();

        if (!$this->hasNetworkColumns()) {
            return;
        }

        $sql = 'SELECT column_index, row_index, slope, park, path
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

        foreach ($this->connection->fetchAllAssociative($sql, $parameters) as $row) {
            yield [
                'column' => (int) $row['column_index'],
                'row' => (int) $row['row_index'],
                'slope' => (float) $row['slope'],
                'park' => (int) $row['park'],
                'path' => (int) $row['path'],
            ];
        }
    }

    public function findNearest(Coordinates $point): ?TerrainProfile
    {
        $row = $this->connection->fetchAssociative(
            'SELECT column_index, row_index, latitude, longitude, elevation, slope, aspect, curvature,
                    cover, edge_distance, water_distance, geology'.$this->accessSelect().$this->canopyCoverSelect().$this->canopyHeightSelect().$this->canopyGapSelect().$this->soilPhSelect().'
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

    /**
     * @param list<array{
     *     row: int, column: int, latitude: float, longitude: float,
     *     elevation: int, slope: float, aspect: float, curvature: float,
     *     cover: int, edge_distance: int, water_distance: int, geology: int,
     *     access_distance: int, canopy_cover: ?int, canopy_height: ?int, canopy_gap: ?int, soil_ph: ?float,
     *     park: int, path: int
     * }> $buffer
     */
    private function flushChunk(array $buffer): void
    {
        $flat = [];
        foreach ($buffer as $cell) {
            $flat[] = $cell['row'];
            $flat[] = $cell['column'];
            $flat[] = $cell['latitude'];
            $flat[] = $cell['longitude'];
            $flat[] = $cell['elevation'];
            $flat[] = $cell['slope'];
            $flat[] = $cell['aspect'];
            $flat[] = $cell['curvature'];
            $flat[] = $cell['cover'];
            $flat[] = $cell['edge_distance'];
            $flat[] = $cell['water_distance'];
            $flat[] = $cell['geology'];
            $flat[] = $cell['access_distance'];
            $flat[] = $cell['canopy_cover'];
            $flat[] = $cell['canopy_height'];
            $flat[] = $cell['canopy_gap'];
            $flat[] = $cell['soil_ph'];
            $flat[] = $cell['park'];
            $flat[] = $cell['path'];
        }

        $this->connection->executeStatement(
            'INSERT INTO terrain_cell
             (row_index, column_index, latitude, longitude, elevation, slope, aspect, curvature, cover, edge_distance, water_distance, geology, access_distance, canopy_cover, canopy_height, canopy_gap, soil_ph, park, path)
             VALUES '.$this->placeholders(\count($buffer)),
            $flat,
        );
    }

    private function placeholders(int $rows): string
    {
        if (!isset($this->placeholderCache[$rows])) {
            $tuple = '('.implode(',', array_fill(0, self::COLUMNS, '?')).')';
            $this->placeholderCache[$rows] = implode(',', array_fill(0, $rows, $tuple));
        }

        return $this->placeholderCache[$rows];
    }

    /** @param array<string, mixed> $row */
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
            substrate: Substrate::tryFrom((int) ($row['geology'] ?? 0)) ?? Substrate::Unknown,
            accessDistanceMeters: (int) ($row['access_distance'] ?? AccessThreshold::UNREACHABLE),
            canopyCoverPercent: isset($row['canopy_cover']) && $row['canopy_cover'] !== null
                ? (int) $row['canopy_cover']
                : null,
            soilPh: isset($row['soil_ph']) && $row['soil_ph'] !== null
                ? (float) $row['soil_ph']
                : null,
            canopyHeightMeters: isset($row['canopy_height']) && $row['canopy_height'] !== null
                ? (int) $row['canopy_height']
                : null,
            canopyGapPercent: isset($row['canopy_gap']) && $row['canopy_gap'] !== null
                ? (int) $row['canopy_gap']
                : null,
        );
    }

    private function accessSelect(): string
    {
        return $this->hasAccessColumn() ? ', access_distance' : '';
    }

    private function canopyCoverSelect(): string
    {
        return $this->hasCanopyCoverColumn() ? ', canopy_cover' : '';
    }

    private function canopyHeightSelect(): string
    {
        return $this->hasCanopyHeightColumn() ? ', canopy_height' : '';
    }

    private function canopyGapSelect(): string
    {
        return $this->hasCanopyGapColumn() ? ', canopy_gap' : '';
    }

    private function soilPhSelect(): string
    {
        return $this->hasSoilPhColumn() ? ', soil_ph' : '';
    }

    private function networkSelect(): string
    {
        return $this->hasNetworkColumns() ? ', park, path' : '';
    }

    private function hasAccessColumn(): bool
    {
        if ($this->hasAccessColumn !== null) {
            return $this->hasAccessColumn;
        }

        try {
            $names = $this->connection->fetchFirstColumn("SELECT name FROM pragma_table_info('terrain_cell')");
            $this->hasAccessColumn = \in_array('access_distance', $names, true);
            $this->hasCanopyCoverColumn = \in_array('canopy_cover', $names, true);
            $this->hasCanopyHeightColumn = \in_array('canopy_height', $names, true);
            $this->hasCanopyGapColumn = \in_array('canopy_gap', $names, true);
            $this->hasSoilPhColumn = \in_array('soil_ph', $names, true);
            $this->hasNetworkColumns = \in_array('park', $names, true) && \in_array('path', $names, true);
        } catch (\Throwable) {
            $this->hasAccessColumn = false;
            $this->hasCanopyCoverColumn = false;
            $this->hasCanopyHeightColumn = false;
            $this->hasCanopyGapColumn = false;
            $this->hasSoilPhColumn = false;
            $this->hasNetworkColumns = false;
        }

        return $this->hasAccessColumn;
    }

    private function hasCanopyCoverColumn(): bool
    {
        if ($this->hasCanopyCoverColumn !== null) {
            return $this->hasCanopyCoverColumn;
        }

        $this->hasAccessColumn();

        return $this->hasCanopyCoverColumn ?? false;
    }

    private function hasCanopyHeightColumn(): bool
    {
        if ($this->hasCanopyHeightColumn !== null) {
            return $this->hasCanopyHeightColumn;
        }

        $this->hasAccessColumn();

        return $this->hasCanopyHeightColumn ?? false;
    }

    private function hasCanopyGapColumn(): bool
    {
        if ($this->hasCanopyGapColumn !== null) {
            return $this->hasCanopyGapColumn;
        }

        $this->hasAccessColumn();

        return $this->hasCanopyGapColumn ?? false;
    }

    private function hasSoilPhColumn(): bool
    {
        if ($this->hasSoilPhColumn !== null) {
            return $this->hasSoilPhColumn;
        }

        $this->hasAccessColumn();

        return $this->hasSoilPhColumn ?? false;
    }

    private function hasNetworkColumns(): bool
    {
        if ($this->hasNetworkColumns !== null) {
            return $this->hasNetworkColumns;
        }

        $this->hasAccessColumn();

        return $this->hasNetworkColumns ?? false;
    }
}
