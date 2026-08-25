#!/usr/bin/env python3
"""Crop a Copernicus HRL Tree Cover Density GeoTIFF onto the 50 m study grid.

Writes backend/var/tcd/canopy-cover.bin (uint8, south→north) and a JSON sidecar.
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

# services.yaml app.area.*
SOUTH, WEST, NORTH, EAST = 44.72, 5.38, 45.45, 6.30
CELL_SIZE_METERS = 50
METERS_PER_DEGREE_LATITUDE = 111_320.0
NODATA = 255


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
        "-srcnodata",
        str(NODATA),
        "-dstnodata",
        str(NODATA),
        "-of",
        "GTiff",
        "-overwrite",
        *[str(path) for path in sources],
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
    files: list[Path] = []
    for product in products:
        if not isinstance(product, dict):
            continue
        name = product.get("path") or product.get("name")
        if not name:
            continue
        candidate = Path(str(name))
        if not candidate.is_absolute():
            candidate = manifest_path.parent / candidate
        if not candidate.suffix:
            candidate = candidate.with_suffix(".tif")
        if not candidate.is_file():
            raise SystemExit(f"Tuile absente : {candidate}")
        files.append(candidate)
    if not files:
        raise SystemExit(f"Aucune tuile lisible dans {manifest_path}")
    return files


def read_gtiff_south_up(path: Path, columns: int, rows: int) -> bytes:
    try:
        from osgeo import gdal
    except ImportError:
        gdal = None

    if gdal is not None:
        ds = gdal.Open(str(path))
        if ds is None:
            raise SystemExit(f"Impossible d'ouvrir {path}")
        band = ds.GetRasterBand(1)
        arr = band.ReadAsArray()
        if arr is None:
            raise SystemExit(f"Bande vide dans {path}")
        # GDAL row 0 is north; our grid row 0 is south.
        flipped = arr[::-1]
        out = bytearray(columns * rows)
        for row in range(min(rows, flipped.shape[0])):
            line = flipped[row]
            for col in range(min(columns, line.shape[0])):
                value = int(line[col])
                if value < 0 or value > 100:
                    value = NODATA
                out[row * columns + col] = value
        return bytes(out)

    # Fallback: gdal_translate to a raw band, then flip rows.
    raw = path.with_suffix(".bil")
    hdr = path.with_suffix(".hdr")
    subprocess.run(
        [
            "gdal_translate",
            "-of",
            "EHdr",
            "-ot",
            "Byte",
            str(path),
            str(raw),
        ],
        check=True,
    )
    data = raw.read_bytes()
    expected = columns * rows
    if len(data) < expected:
        raise SystemExit(f"Raster trop petit : {len(data)} octets, attendu {expected}")
    lines = [data[i * columns : (i + 1) * columns] for i in range(rows)]
    flipped = b"".join(reversed(lines))
    cleaned = bytearray(flipped)
    for i, value in enumerate(cleaned):
        if value > 100:
            cleaned[i] = NODATA
    raw.unlink(missing_ok=True)
    hdr.unlink(missing_ok=True)
    return bytes(cleaned)


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Recadre Copernicus Tree Cover Density sur la grille 50 m."
    )
    parser.add_argument("prefix", type=Path, help="Préfixe de sortie (sans extension)")
    parser.add_argument(
        "sources",
        nargs="*",
        type=Path,
        help="GeoTIFF source(s). Inutile si --manifest est fourni.",
    )
    parser.add_argument(
        "--manifest",
        type=Path,
        help="manifest.json écrit par fetch_tcd.py (année et ids produits)",
    )
    return parser.parse_args(argv)


def main() -> int:
    args = parse_args(sys.argv[1:])
    prefix: Path = args.prefix
    manifest: dict[str, Any] | None = None
    if args.manifest is not None:
        if not args.manifest.is_file():
            print(f"Introuvable : {args.manifest}", file=sys.stderr)
            return 1
        manifest = load_manifest(args.manifest)

    sources: list[Path] = list(args.sources)
    if manifest is not None and not sources:
        if args.manifest is None:
            raise SystemExit("Manifeste manquant")
        sources = sources_from_manifest(args.manifest, manifest)

    if not sources:
        print(
            "Usage: convert_tcd.py <sortie-prefix> [--manifest fichier.json] <source.tif>…",
            file=sys.stderr,
        )
        return 1

    for source in sources:
        if not source.is_file():
            print(f"Introuvable : {source}", file=sys.stderr)
            return 1

    columns, rows, latitude_step, longitude_step = lattice()
    prefix.parent.mkdir(parents=True, exist_ok=True)

    with tempfile.TemporaryDirectory() as tmp:
        warped = Path(tmp) / "tcd-wgs84.tif"
        print(f"Reprojection vers la grille {columns} × {rows} à 50 m…")
        run_gdalwarp(sources, warped, columns, rows)
        payload = read_gtiff_south_up(warped, columns, rows)

    bin_path = prefix.with_suffix(".bin")
    json_path = prefix.with_suffix(".json")
    bin_path.write_bytes(payload)

    known = sum(1 for value in payload if value <= 100)
    sidecar: dict[str, Any] = {
        "south": SOUTH,
        "west": WEST,
        "north": NORTH,
        "east": EAST,
        "cellSizeMeters": CELL_SIZE_METERS,
        "columns": columns,
        "rows": rows,
        "latitudeStep": latitude_step,
        "longitudeStep": longitude_step,
        "nodata": NODATA,
        "knownCells": known,
        "source": "Copernicus HRL Tree Cover Density",
    }
    if manifest is not None:
        if manifest.get("datasetIdentifier"):
            sidecar["datasetIdentifier"] = manifest["datasetIdentifier"]
        if manifest.get("year") is not None:
            sidecar["year"] = manifest["year"]
        products = manifest.get("products")
        if isinstance(products, list):
            sidecar["productIds"] = [
                item.get("id") for item in products if isinstance(item, dict) and item.get("id")
            ]
            sidecar["productNames"] = [
                item.get("name")
                for item in products
                if isinstance(item, dict) and item.get("name")
            ]
    json_path.write_text(json.dumps(sidecar, indent=2) + "\n", encoding="utf-8")

    print(
        f"TCD prêt : {bin_path} ({bin_path.stat().st_size} octets, "
        f"{known} mailles renseignées / {columns * rows})"
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except subprocess.CalledProcessError as error:
        print(f"GDAL a échoué : {error}", file=sys.stderr)
        raise SystemExit(1)
