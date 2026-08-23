<?php

declare(strict_types=1);

namespace App\Domain\Geo;

/**
 * Regular lat/lng lattice with an approximately constant cell size in metres.
 */
final readonly class Grid
{
    private const METERS_PER_DEGREE_LATITUDE = 111_320.0;

    public float $latitudeStep;
    public float $longitudeStep;
    public int $columns;
    public int $rows;

    public function __construct(
        public BoundingBox $bounds,
        public int $cellSizeMeters,
    ) {
        $meanLatitude = ($bounds->south + $bounds->north) / 2;

        $this->latitudeStep = $cellSizeMeters / self::METERS_PER_DEGREE_LATITUDE;
        $this->longitudeStep = $cellSizeMeters
            / (self::METERS_PER_DEGREE_LATITUDE * cos(deg2rad($meanLatitude)));

        $this->columns = (int) floor(($bounds->east - $bounds->west) / $this->longitudeStep);
        $this->rows = (int) floor(($bounds->north - $bounds->south) / $this->latitudeStep);
    }

    public function latitudeAt(int $row): float
    {
        return $this->bounds->south + ($row + 0.5) * $this->latitudeStep;
    }

    public function longitudeAt(int $column): float
    {
        return $this->bounds->west + ($column + 0.5) * $this->longitudeStep;
    }

    public function columnFor(float $longitude): int
    {
        return (int) floor(($longitude - $this->bounds->west) / $this->longitudeStep);
    }

    public function rowFor(float $latitude): int
    {
        return (int) floor(($latitude - $this->bounds->south) / $this->latitudeStep);
    }

    public function offset(int $column, int $row): int
    {
        return $row * $this->columns + $column;
    }

    public function cellCount(): int
    {
        return $this->columns * $this->rows;
    }

    public function coordinatesAt(int $column, int $row): Coordinates
    {
        return new Coordinates($this->latitudeAt($row), $this->longitudeAt($column));
    }

    /**
     * Clamps a viewport to the grid and picks a decimation step keeping the payload
     * under $maxCells while staying as detailed as possible.
     */
    public function windowFor(BoundingBox $viewport, int $maxCells): ?GridWindow
    {
        $visible = $this->bounds->intersect($viewport);
        if ($visible === null) {
            return null;
        }

        $firstColumn = max(0, $this->columnFor($visible->west));
        $lastColumn = min($this->columns - 1, $this->columnFor($visible->east));
        $firstRow = max(0, $this->rowFor($visible->south));
        $lastRow = min($this->rows - 1, $this->rowFor($visible->north));

        if ($firstColumn > $lastColumn || $firstRow > $lastRow) {
            return null;
        }

        $width = $lastColumn - $firstColumn + 1;
        $height = $lastRow - $firstRow + 1;
        $step = 1;
        while ((int) ceil($width / $step) * (int) ceil($height / $step) > $maxCells) {
            $step++;
        }

        return new GridWindow($firstColumn, $lastColumn, $firstRow, $lastRow, $step);
    }
}
