#!/usr/bin/env python3
"""Crop IGN RGE ALTI GeoTIFF mosaics onto the 50 m study grid.

Writes backend/var/elevation/rge-alti.bin (int16 little-endian decimetres, south→north)
and a JSON sidecar. Missing mosaic → Terrarium remains the elevation source.

Keep the lattice formula in sync with App\\Domain\\Geo\\Grid.
"""

from __future__ import annotations

import argparse
import json
import math
import struct
import subprocess
import sys
import tempfile
from pathlib import Path
from typing import Any

SOUTH, WEST, NORTH, EAST = 44.72, 5.38, 45.45, 6.30
CELL_SIZE_METERS = 50
METERS_PER_DEGREE_LATITUDE = 111_320.0
NODATA_DM = -32768


def lattice() -> tuple[int, int, float, float]:
    mean_lat = (SOUTH + NORTH) / 2.0
    latitude_step = CELL_SIZE_METERS / METERS_PER_DEGREE_LATITUDE
    longitude_step = CELL_SIZE_METERS / (
        METERS_PER_DEGREE_LATITUDE * math.cos(math.radians(mean_lat))
    )
    columns = int((EAST - WEST) / longitude_step)
    rows = int((NORTH - SOUTH) / latitude_step)
    return columns, rows, latitude_step, longitude_step


def run_gdalwarp(sources: list[Path], dest: Path, columns: int, rows: int) -> None:
    cmd = [
        "gdalwarp",
        "-t_srs",
        "EPSG:4326",
        "-te",
        str(WEST),
        str(SOUTH),
        str(EAST),
        str(NORTH),
        "-ts",
        str(columns),
        str(rows),
        "-r",
        "bilinear",
        "-dstnodata",
        "-9999",
        "-of",
        "GTiff",
        "-overwrite",
        *[str(path) for path in sources],
        str(dest),
    ]
    subprocess.run(cmd, check=True)


def sources_from_manifest(manifest_path: Path) -> list[Path]:
    decoded = json.loads(manifest_path.read_text(encoding="utf-8"))
    products = decoded.get("products") if isinstance(decoded, dict) else None
    if not isinstance(products, list) or not products:
        raise SystemExit(f"Manifeste sans dalles : {manifest_path}")
    base = manifest_path.parent
    files: list[Path] = []
    for product in products:
        if not isinstance(product, dict):
            continue
        name = product.get("path") or product.get("name")
        if not name:
            continue
        path = Path(str(name))
        if not path.is_absolute():
            path = base / path
        if path.is_file():
            files.append(path)
    if not files:
        raise SystemExit(f"Aucune dalle RGE ALTI dans {manifest_path}")
    return files


def write_grid(gtiff: Path, dest_prefix: Path, columns: int, rows: int, lat_step: float, lng_step: float) -> None:
    from osgeo import gdal

    gdal.UseExceptions()
    ds = gdal.Open(str(gtiff))
    band = ds.GetRasterBand(1)
    arr = band.ReadAsArray()
    nodata = band.GetNoDataValue()
    out = bytearray()
    for row in range(rows):
        for col in range(columns):
            value = float(arr[row, col])
            if nodata is not None and value == float(nodata):
                out += struct.pack("<h", NODATA_DM)
            elif math.isnan(value):
                out += struct.pack("<h", NODATA_DM)
            else:
                dm = int(round(value * 10.0))
                dm = max(-32000, min(32000, dm))
                out += struct.pack("<h", dm)
    dest_prefix.with_suffix(".bin").write_bytes(out)
    meta = {
        "south": SOUTH,
        "west": WEST,
        "north": NORTH,
        "east": EAST,
        "columns": columns,
        "rows": rows,
        "latitudeStep": lat_step,
        "longitudeStep": lng_step,
        "unit": "decimetres",
        "nodata": NODATA_DM,
        "source": "rge-alti",
    }
    dest_prefix.with_suffix(".json").write_text(json.dumps(meta, indent=2) + "\n", encoding="utf-8")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("prefix", type=Path)
    parser.add_argument("sources", nargs="*", type=Path)
    parser.add_argument("--manifest", type=Path, default=None)
    args = parser.parse_args()

    columns, rows, lat_step, lng_step = lattice()
    sources: list[Path] = []
    if args.manifest:
        sources = sources_from_manifest(args.manifest)
    sources.extend(path for path in args.sources if path.is_file())
    if not sources:
        raise SystemExit(
            "Fournissez des GeoTIFF RGE ALTI ou --manifest. "
            "Voir https://geoservices.ign.fr/rgealti"
        )

    args.prefix.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="myco-rge-") as tmp:
        warped = Path(tmp) / "dem.tif"
        run_gdalwarp(sources, warped, columns, rows)
        write_grid(warped, args.prefix, columns, rows, lat_step, lng_step)

    print(f"Écrit {args.prefix}.bin / .json ({columns}×{rows})")


if __name__ == "__main__":
    try:
        main()
    except subprocess.CalledProcessError as exc:
        print(f"gdalwarp a échoué ({exc.returncode})", file=sys.stderr)
        raise SystemExit(exc.returncode) from exc
