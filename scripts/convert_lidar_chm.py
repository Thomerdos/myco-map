#!/usr/bin/env python3
"""Crop a LIDAR HD canopy-height GeoTIFF (CHM, metres) onto the 50 m study grid.

Writes backend/var/lidar/canopy-height.bin (uint8 metres, 255 = unknown) + JSON sidecar.
Provide GeoTIFF paths (MNS−MNT or ready CHM) or a manifest.json listing them.

Keep the lattice formula in sync with App\\Domain\\Geo\\Grid.
"""

from __future__ import annotations

import argparse
import json
import math
import subprocess
import sys
import tempfile
from pathlib import Path
from typing import Any

SOUTH, WEST, NORTH, EAST = 44.72, 5.38, 45.45, 6.30
CELL_SIZE_METERS = 50
METERS_PER_DEGREE_LATITUDE = 111_320.0
NODATA = 255
MAX_METERS = 80
# Aggregate fine CHM (~10 m) into 50 m cells: mean height + gap fraction.
FINE_FACTOR = 5
GAP_THRESHOLD_M = 3.0


def lattice() -> tuple[int, int, float, float]:
    mean_lat = (SOUTH + NORTH) / 2.0
    latitude_step = CELL_SIZE_METERS / METERS_PER_DEGREE_LATITUDE
    longitude_step = CELL_SIZE_METERS / (
        METERS_PER_DEGREE_LATITUDE * math.cos(math.radians(mean_lat))
    )
    columns = int((EAST - WEST) / longitude_step)
    rows = int((NORTH - SOUTH) / latitude_step)
    return columns, rows, latitude_step, longitude_step


def collect_sources(args: argparse.Namespace) -> list[Path]:
    sources: list[Path] = []
    if args.manifest:
        sources.extend(sources_from_manifest(args.manifest, load_manifest(args.manifest)))
    for path in args.sources:
        if path.is_dir():
            sources.extend(sorted(path.glob("*.tif")))
            sources.extend(sorted(path.glob("*.tiff")))
        elif path.is_file():
            sources.append(path)
    # de-dupe while preserving order
    seen: set[Path] = set()
    unique: list[Path] = []
    for path in sources:
        resolved = path.resolve()
        if resolved in seen:
            continue
        seen.add(resolved)
        unique.append(path)
    return unique


def run_gdalwarp(sources: list[Path], dest: Path, columns: int, rows: int) -> None:
    with tempfile.TemporaryDirectory(prefix="myco-lidar-vrt-") as tmp:
        vrt = Path(tmp) / "mosaic.vrt"
        if len(sources) == 1:
            mosaic = sources[0]
        else:
            list_file = Path(tmp) / "inputs.txt"
            list_file.write_text(
                "\n".join(str(path.resolve()) for path in sources) + "\n",
                encoding="utf-8",
            )
            subprocess.run(
                [
                    "gdalbuildvrt",
                    "-a_srs",
                    "EPSG:2154",
                    "-input_file_list",
                    str(list_file),
                    str(vrt),
                ],
                check=True,
            )
            mosaic = vrt
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
            "average",
            "-dstnodata",
            "-9999",
            "-of",
            "GTiff",
            "-overwrite",
            str(mosaic),
            str(dest),
        ]
        subprocess.run(cmd, check=True)


def load_manifest(path: Path) -> dict[str, Any]:
    decoded = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(decoded, dict):
        raise SystemExit(f"Manifeste invalide : {path}")
    return decoded


def sources_from_manifest(manifest_path: Path, manifest: dict[str, Any]) -> list[Path]:
    products = manifest.get("products")
    if not isinstance(products, list) or not products:
        raise SystemExit(f"Manifeste sans tuiles : {manifest_path}")
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
        raise SystemExit(f"Aucune tuile CHM lisible dans {manifest_path}")
    return files


def write_structure_grids(
    fine_gtiff: Path,
    height_prefix: Path,
    columns: int,
    rows: int,
    lat_step: float,
    lng_step: float,
) -> None:
    """Aggregate a fine CHM into 50 m mean height + gap % (pixels < GAP_THRESHOLD_M)."""
    from osgeo import gdal
    import numpy as np

    gdal.UseExceptions()
    ds = gdal.Open(str(fine_gtiff))
    band = ds.GetRasterBand(1)
    arr = band.ReadAsArray().astype(np.float32)
    nodata = band.GetNoDataValue()
    if nodata is not None:
        arr = np.where(arr == float(nodata), np.nan, arr)
    arr = np.where(arr < 0, np.nan, arr)

    # GDAL row 0 is north; flip so row 0 = south before block aggregation.
    arr = arr[::-1]
    factor = FINE_FACTOR
    expected_f_rows = rows * factor
    expected_f_cols = columns * factor
    if arr.shape[0] < expected_f_rows or arr.shape[1] < expected_f_cols:
        raise SystemExit(
            f"Grille fine trop petite : {arr.shape} vs {expected_f_rows}×{expected_f_cols}"
        )
    arr = arr[:expected_f_rows, :expected_f_cols]
    blocks = arr.reshape(rows, factor, columns, factor)

    height = np.nanmean(blocks, axis=(1, 3))
    valid = np.sum(~np.isnan(blocks), axis=(1, 3))
    gaps = np.sum(np.where(np.isnan(blocks), False, blocks < GAP_THRESHOLD_M), axis=(1, 3))
    gap_pct = np.where(valid > 0, np.round(100.0 * gaps / valid), np.nan)

    height_out = bytearray(columns * rows)
    gap_out = bytearray(columns * rows)
    known = 0
    for row in range(rows):
        for col in range(columns):
            idx = row * columns + col
            h = float(height[row, col])
            g = float(gap_pct[row, col])
            if math.isnan(h) or valid[row, col] == 0:
                height_out[idx] = NODATA
                gap_out[idx] = NODATA
            else:
                height_out[idx] = min(MAX_METERS, int(round(h)))
                gap_out[idx] = min(100, int(round(g)))
                known += 1

    height_prefix.parent.mkdir(parents=True, exist_ok=True)
    height_prefix.with_suffix(".bin").write_bytes(height_out)
    gap_prefix = height_prefix.parent / "canopy-gap"
    gap_prefix.with_suffix(".bin").write_bytes(gap_out)

    meta_common = {
        "south": SOUTH,
        "west": WEST,
        "north": NORTH,
        "east": EAST,
        "columns": columns,
        "rows": rows,
        "latitudeStep": lat_step,
        "longitudeStep": lng_step,
        "nodata": NODATA,
        "fineFactor": factor,
        "gapThresholdMeters": GAP_THRESHOLD_M,
        "knownCells": known,
    }
    height_meta = {
        **meta_common,
        "unit": "metres",
        "source": "lidar-hd-chm",
    }
    gap_meta = {
        **meta_common,
        "unit": "percent",
        "source": "lidar-hd-gap",
    }
    height_prefix.with_suffix(".json").write_text(
        json.dumps(height_meta, indent=2) + "\n", encoding="utf-8"
    )
    gap_prefix.with_suffix(".json").write_text(
        json.dumps(gap_meta, indent=2) + "\n", encoding="utf-8"
    )
    print(
        f"Écrit {height_prefix}.bin + {gap_prefix}.bin "
        f"({known}/{columns * rows} mailles, gap < {GAP_THRESHOLD_M} m)",
        flush=True,
    )


def write_grid(gtiff: Path, dest_prefix: Path, columns: int, rows: int, lat_step: float, lng_step: float) -> None:
    """Legacy single-band height writer (kept for one-file CHM inputs)."""
    from osgeo import gdal

    gdal.UseExceptions()
    ds = gdal.Open(str(gtiff))
    band = ds.GetRasterBand(1)
    arr = band.ReadAsArray()
    nodata = band.GetNoDataValue()
    flipped = arr[::-1]
    out = bytearray(columns * rows)
    for row in range(min(rows, flipped.shape[0])):
        line = flipped[row]
        for col in range(min(columns, line.shape[0])):
            value = float(line[col])
            if nodata is not None and value == float(nodata):
                out[row * columns + col] = NODATA
            elif value < 0 or math.isnan(value):
                out[row * columns + col] = NODATA
            else:
                out[row * columns + col] = min(MAX_METERS, int(round(value)))
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
        "unit": "metres",
        "nodata": NODATA,
        "source": "lidar-hd-chm",
    }
    dest_prefix.with_suffix(".json").write_text(json.dumps(meta, indent=2) + "\n", encoding="utf-8")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("prefix", type=Path, help="Output prefix …/canopy-height")
    parser.add_argument("sources", nargs="*", type=Path, help="CHM GeoTIFF files or a tiles/ directory")
    parser.add_argument("--manifest", type=Path, default=None)
    args = parser.parse_args()

    columns, rows, lat_step, lng_step = lattice()
    sources = collect_sources(args)
    if not sources:
        raise SystemExit(
            "Fournissez des GeoTIFF CHM/MNH (fichiers ou dossier) ou --manifest. "
            "Voir https://geoservices.ign.fr/lidarhd"
        )

    print(f"Mosaïque de {len(sources)} dalle(s) → grille {columns}×{rows} (+ structure ×{FINE_FACTOR})", flush=True)
    args.prefix.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="myco-lidar-") as tmp:
        fine = Path(tmp) / "chm_fine.tif"
        run_gdalwarp(sources, fine, columns * FINE_FACTOR, rows * FINE_FACTOR)
        if len(sources) >= 8:
            write_structure_grids(fine, args.prefix, columns, rows, lat_step, lng_step)
        else:
            # Single ready-made CHM: keep simple height mosaic at 50 m.
            warped = Path(tmp) / "chm.tif"
            run_gdalwarp(sources, warped, columns, rows)
            write_grid(warped, args.prefix, columns, rows, lat_step, lng_step)
            print(f"Écrit {args.prefix}.bin / .json ({columns}×{rows})")


if __name__ == "__main__":
    try:
        main()
    except subprocess.CalledProcessError as exc:
        print(f"gdalwarp a échoué ({exc.returncode})", file=sys.stderr)
        raise SystemExit(exc.returncode) from exc
