#!/usr/bin/env python3
"""Build the 50 m canopy-height grid from downloaded MNH tiles (no Docker/GDAL CLI).

Uses rasterio to mosaic + reproject onto the study lattice. Output matches
convert_lidar_chm.py (uint8 metres + JSON sidecar).
"""

from __future__ import annotations

import argparse
import json
import math
from pathlib import Path

import numpy as np
import rasterio
from rasterio.merge import merge
from rasterio.warp import Resampling, calculate_default_transform, reproject

SOUTH, WEST, NORTH, EAST = 44.72, 5.38, 45.45, 6.30
CELL_SIZE_METERS = 50
METERS_PER_DEGREE_LATITUDE = 111_320.0
NODATA = 255
MAX_METERS = 80


def lattice() -> tuple[int, int, float, float]:
    mean_lat = (SOUTH + NORTH) / 2.0
    latitude_step = CELL_SIZE_METERS / METERS_PER_DEGREE_LATITUDE
    longitude_step = CELL_SIZE_METERS / (
        METERS_PER_DEGREE_LATITUDE * math.cos(math.radians(mean_lat))
    )
    columns = int((EAST - WEST) / longitude_step)
    rows = int((NORTH - SOUTH) / latitude_step)
    return columns, rows, latitude_step, longitude_step


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("tiles_dir", type=Path)
    parser.add_argument("prefix", type=Path, help="Output prefix …/canopy-height")
    args = parser.parse_args()

    files = sorted(args.tiles_dir.glob("*.tif")) + sorted(args.tiles_dir.glob("*.tiff"))
    if not files:
        raise SystemExit(f"Aucune dalle GeoTIFF dans {args.tiles_dir}")

    columns, rows, lat_step, lng_step = lattice()
    print(f"Mosaïque {len(files)} dalles → {columns}×{rows}", flush=True)

    datasets = [rasterio.open(path) for path in files]
    try:
        mosaic, transform = merge(datasets, nodata=-9999.0)
    finally:
        for ds in datasets:
            ds.close()

    src = mosaic[0]
    dst = np.full((rows, columns), -9999.0, dtype=np.float32)
    dst_transform = rasterio.transform.from_bounds(WEST, SOUTH, EAST, NORTH, columns, rows)
    reproject(
        source=src,
        destination=dst,
        src_transform=transform,
        src_crs="EPSG:2154",
        src_nodata=-9999.0,
        dst_transform=dst_transform,
        dst_crs="EPSG:4326",
        dst_nodata=-9999.0,
        resampling=Resampling.average,
    )

    out = bytearray(columns * rows)
    known = 0
    for row in range(rows):
        for col in range(columns):
            value = float(dst[row, col])
            if value < 0 or math.isnan(value) or value == -9999.0:
                out[row * columns + col] = NODATA
            else:
                out[row * columns + col] = min(MAX_METERS, int(round(value)))
                known += 1

    # rasterio rows are north→south when using from_bounds; our lattice is south→north.
    flipped = bytearray(columns * rows)
    for row in range(rows):
        src_row = rows - 1 - row
        flipped[row * columns : (row + 1) * columns] = out[src_row * columns : (src_row + 1) * columns]

    args.prefix.parent.mkdir(parents=True, exist_ok=True)
    args.prefix.with_suffix(".bin").write_bytes(flipped)
    meta = {
        "south": SOUTH,
        "west": WEST,
        "north": NORTH,
        "east": EAST,
        "columns": columns,
        "rows": rows,
        "latitudeStep": lat_step,
        "longitudeStep": lng_step,
        "unit": "metres",
        "nodata": NODATA,
        "source": "lidar-hd-mnh",
        "knownCells": known,
    }
    args.prefix.with_suffix(".json").write_text(json.dumps(meta, indent=2) + "\n", encoding="utf-8")
    print(f"Écrit {args.prefix}.bin / .json — {known}/{columns * rows} mailles renseignées")


if __name__ == "__main__":
    main()
