#!/usr/bin/env python3
"""Crop EcoDataCube soil pH (Byte × 0.1) onto the 50 m study grid.

Writes backend/var/soilph/soil-ph.bin (uint8, south→north, 255 = unknown) and a JSON sidecar.
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
SCALE = 0.1  # stored uint8: 65 → pH 6.5


def lattice() -> tuple[int, int, float, float]:
    mean_lat = (SOUTH + NORTH) / 2.0
    latitude_step = CELL_SIZE_METERS / METERS_PER_DEGREE_LATITUDE
    longitude_step = CELL_SIZE_METERS / (
        METERS_PER_DEGREE_LATITUDE * math.cos(math.radians(mean_lat))
    )
    columns = int((EAST - WEST) / longitude_step)
    rows = int((NORTH - SOUTH) / latitude_step)
    return columns, rows, latitude_step, longitude_step


def load_manifest(path: Path) -> dict[str, Any]:
    decoded = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(decoded, dict):
        raise SystemExit(f"Manifeste invalide : {path}")
    return decoded


def resolve_source(args: argparse.Namespace) -> tuple[str, dict[str, Any] | None]:
    if args.manifest is not None:
        manifest = load_manifest(args.manifest)
        href = manifest.get("href")
        if not href:
            raise SystemExit(f"Manifeste sans href : {args.manifest}")
        return str(href), manifest
    if args.source:
        return str(args.source), None
    raise SystemExit("Passez --manifest ou une URL / chemin GeoTIFF source.")


def run_gdalwarp(source: str, dest: Path, columns: int, rows: int) -> None:
    # /vsicurl/ lets GDAL read only the COG windows overlapping the emprise.
    src = source if source.startswith("/vsi") or Path(source).exists() else f"/vsicurl/{source}"
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
        "-ot",
        "Byte",
        "-srcnodata",
        str(NODATA),
        "-dstnodata",
        str(NODATA),
        "-of",
        "GTiff",
        "-overwrite",
        src,
        str(dest),
    ]
    subprocess.run(cmd, check=True)


def read_gtiff_south_up(path: Path, columns: int, rows: int) -> bytes:
    from osgeo import gdal

    ds = gdal.Open(str(path))
    if ds is None:
        raise SystemExit(f"Impossible d'ouvrir {path}")
    band = ds.GetRasterBand(1)
    arr = band.ReadAsArray()
    if arr is None:
        raise SystemExit(f"Bande vide dans {path}")
    flipped = arr[::-1]
    out = bytearray(columns * rows)
    for row in range(min(rows, flipped.shape[0])):
        line = flipped[row]
        for col in range(min(columns, line.shape[0])):
            value = int(line[col])
            if value < 0 or value >= NODATA:
                value = NODATA
            out[row * columns + col] = value
    return bytes(out)


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Recadre le pH EcoDataCube sur la grille 50 m."
    )
    parser.add_argument("prefix", type=Path, help="Préfixe de sortie (sans extension)")
    parser.add_argument(
        "source",
        nargs="?",
        help="URL HTTPS du COG ou chemin local .tif (sinon --manifest)",
    )
    parser.add_argument(
        "--manifest",
        type=Path,
        help="manifest.json écrit par fetch_soil_ph.py",
    )
    return parser.parse_args(argv)


def main() -> int:
    args = parse_args(sys.argv[1:])
    source, manifest = resolve_source(args)
    columns, rows, latitude_step, longitude_step = lattice()
    args.prefix.parent.mkdir(parents=True, exist_ok=True)

    with tempfile.TemporaryDirectory() as tmp:
        warped = Path(tmp) / "soil-ph-wgs84.tif"
        print(f"Reprojection pH vers la grille {columns} × {rows} à 50 m…", flush=True)
        run_gdalwarp(source, warped, columns, rows)
        payload = read_gtiff_south_up(warped, columns, rows)

    bin_path = args.prefix.with_suffix(".bin")
    json_path = args.prefix.with_suffix(".json")
    bin_path.write_bytes(payload)

    known = sum(1 for value in payload if value < NODATA)
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
        "scale": SCALE,
        "unit": "pH (H2O)",
        "knownCells": known,
        "source": "EcoDataCube / AI4SoilHealth soil pH H2O 30 m",
        "depth": "0-20cm",
    }
    if manifest is not None:
        for key in ("itemId", "href", "start", "end", "assetKey"):
            if manifest.get(key) is not None:
                sidecar[key] = manifest[key]

    json_path.write_text(json.dumps(sidecar, indent=2) + "\n", encoding="utf-8")
    print(
        f"pH prêt : {bin_path} ({bin_path.stat().st_size} octets, "
        f"{known} mailles renseignées / {columns * rows})"
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except subprocess.CalledProcessError as error:
        print(f"GDAL a échoué : {error}", file=sys.stderr)
        raise SystemExit(1)
    except OSError as error:
        print(f"Réseau / fichier : {error}", file=sys.stderr)
        raise SystemExit(1)
