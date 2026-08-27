#!/usr/bin/env python3
"""Crop IGN RGE ALTI DEM (GeoTIFF or ASC) onto the 50 m study grid.

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
RASTER_SUFFIXES = {".tif", ".tiff", ".asc", ".txt"}


def lattice() -> tuple[int, int, float, float]:
    mean_lat = (SOUTH + NORTH) / 2.0
    latitude_step = CELL_SIZE_METERS / METERS_PER_DEGREE_LATITUDE
    longitude_step = CELL_SIZE_METERS / (
        METERS_PER_DEGREE_LATITUDE * math.cos(math.radians(mean_lat))
    )
    columns = int((EAST - WEST) / longitude_step)
    rows = int((NORTH - SOUTH) / latitude_step)
    return columns, rows, latitude_step, longitude_step


def study_bbox_lamb93() -> tuple[float, float, float, float]:
    """Return (minx, miny, maxx, maxy) of the WGS84 study window in EPSG:2154."""
    from osgeo import osr

    source = osr.SpatialReference()
    source.ImportFromEPSG(4326)
    source.SetAxisMappingStrategy(osr.OAMS_TRADITIONAL_GIS_ORDER)
    target = osr.SpatialReference()
    target.ImportFromEPSG(2154)
    target.SetAxisMappingStrategy(osr.OAMS_TRADITIONAL_GIS_ORDER)
    transform = osr.CoordinateTransformation(source, target)
    xs: list[float] = []
    ys: list[float] = []
    for lat in (SOUTH, NORTH):
        for lng in (WEST, EAST):
            x, y, _ = transform.TransformPoint(lng, lat)
            xs.append(x)
            ys.append(y)
    pad = 200.0
    return min(xs) - pad, min(ys) - pad, max(xs) + pad, max(ys) + pad


def asc_header_extent(path: Path) -> tuple[float, float, float, float] | None:
    """Parse ESRI ASCII header → (minx, miny, maxx, maxy) in the file CRS."""
    try:
        with path.open("r", encoding="utf-8", errors="replace") as handle:
            header: dict[str, float] = {}
            for _ in range(12):
                line = handle.readline()
                if not line:
                    break
                parts = line.split()
                if len(parts) < 2:
                    continue
                key = parts[0].lower()
                try:
                    header[key] = float(parts[1])
                except ValueError:
                    continue
                if len(header) >= 5 and ("xllcorner" in header or "xllcenter" in header):
                    break
    except OSError:
        return None

    ncols = header.get("ncols")
    nrows = header.get("nrows")
    cell = header.get("cellsize")
    if not ncols or not nrows or not cell:
        return None
    if "xllcorner" in header and "yllcorner" in header:
        minx = header["xllcorner"]
        miny = header["yllcorner"]
    elif "xllcenter" in header and "yllcenter" in header:
        minx = header["xllcenter"] - cell / 2.0
        miny = header["yllcenter"] - cell / 2.0
    else:
        return None
    maxx = minx + ncols * cell
    maxy = miny + nrows * cell
    return minx, miny, maxx, maxy


def intersects(a: tuple[float, float, float, float], b: tuple[float, float, float, float]) -> bool:
    return a[0] < b[2] and a[2] > b[0] and a[1] < b[3] and a[3] > b[1]


def collect_rasters(path: Path) -> list[Path]:
    if path.is_file() and path.suffix.lower() in RASTER_SUFFIXES:
        return [path]
    if not path.is_dir():
        return []
    found: list[Path] = []
    for child in sorted(path.rglob("*")):
        if child.is_file() and child.suffix.lower() in RASTER_SUFFIXES:
            found.append(child)
    return found


def filter_to_study(paths: list[Path]) -> list[Path]:
    """Keep ASC/TIFF that intersect the study window (ASC via header in L93)."""
    bbox = study_bbox_lamb93()
    kept: list[Path] = []
    skipped = 0
    for path in paths:
        if path.suffix.lower() == ".asc":
            extent = asc_header_extent(path)
            if extent is None:
                kept.append(path)
                continue
            if intersects(extent, bbox):
                kept.append(path)
            else:
                skipped += 1
        else:
            # GeoTIFF / unknown: let gdalwarp crop; usually few files when manual.
            kept.append(path)
    if skipped:
        print(f"Filtre emprise : {len(kept)} dalles gardées, {skipped} hors zone", flush=True)
    return kept


def expand_product_paths(base: Path, product: dict[str, Any]) -> list[Path]:
    paths: list[Path] = []
    extracted = product.get("extracted")
    if extracted:
        paths.extend(collect_rasters(base / str(extracted)))
    raw = product.get("path") or product.get("name")
    candidates: list[str] = []
    if isinstance(raw, list):
        candidates = [str(item) for item in raw]
    elif raw:
        candidates = [str(raw)]
    for name in candidates:
        path = Path(name)
        if not path.is_absolute():
            path = base / path
        if path.suffix.lower() in {".7z", ".zip"}:
            continue
        paths.extend(collect_rasters(path) if path.is_dir() else ([path] if path.is_file() else []))
    for name in product.get("files") or []:
        path = Path(str(name))
        if not path.is_absolute():
            path = base / path
        if path.is_file() and path.suffix.lower() in RASTER_SUFFIXES:
            paths.append(path)
    # Deduplicate while preserving order.
    seen: set[Path] = set()
    unique: list[Path] = []
    for path in paths:
        resolved = path.resolve()
        if resolved in seen:
            continue
        seen.add(resolved)
        unique.append(path)
    return unique


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
        files.extend(expand_product_paths(base, product))
    if not files:
        raise SystemExit(
            f"Aucune dalle ASC/GeoTIFF trouvée via {manifest_path}. "
            "Extraire les archives .7z vers extracted/<package>/ d'abord."
        )
    return filter_to_study(files)


def run_gdalwarp(sources: list[Path], dest: Path, columns: int, rows: int) -> None:
    with tempfile.TemporaryDirectory(prefix="myco-rge-vrt-") as tmp:
        list_path = Path(tmp) / "inputs.txt"
        list_path.write_text("\n".join(str(path) for path in sources) + "\n", encoding="utf-8")
        vrt = Path(tmp) / "mosaic.vrt"
        # ASC IGN packages ship without sidecar .prj — force Lambert-93.
        subprocess.run(
            [
                "gdalbuildvrt",
                "-a_srs",
                "EPSG:2154",
                "-input_file_list",
                str(list_path),
                str(vrt),
            ],
            check=True,
        )
        cmd = [
            "gdalwarp",
            "-s_srs",
            "EPSG:2154",
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
            "-99999",
            "-dstnodata",
            "-9999",
            "-of",
            "GTiff",
            "-overwrite",
            str(vrt),
            str(dest),
        ]
        subprocess.run(cmd, check=True)


def write_grid(gtiff: Path, dest_prefix: Path, columns: int, rows: int, lat_step: float, lng_step: float) -> None:
    from osgeo import gdal

    gdal.UseExceptions()
    ds = gdal.Open(str(gtiff))
    band = ds.GetRasterBand(1)
    arr = band.ReadAsArray()
    nodata = band.GetNoDataValue()
    # GDAL row 0 is north; our grid row 0 is south.
    flipped = arr[::-1]
    out = bytearray()
    for row in range(min(rows, flipped.shape[0])):
        line = flipped[row]
        for col in range(min(columns, line.shape[0])):
            value = float(line[col])
            if nodata is not None and value == float(nodata):
                out += struct.pack("<h", NODATA_DM)
            elif math.isnan(value):
                out += struct.pack("<h", NODATA_DM)
            else:
                dm = int(round(value * 10.0))
                dm = max(-32000, min(32000, dm))
                out += struct.pack("<h", dm)
    expected = columns * rows * 2
    if len(out) < expected:
        out.extend(struct.pack("<h", NODATA_DM) * ((expected - len(out)) // 2))
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
    for path in args.sources:
        sources.extend(collect_rasters(path))
    sources = filter_to_study(sources) if args.sources else sources
    # Deduplicate
    seen: set[Path] = set()
    unique: list[Path] = []
    for path in sources:
        key = path.resolve()
        if key in seen:
            continue
        seen.add(key)
        unique.append(path)
    sources = unique

    if not sources:
        raise SystemExit(
            "Fournissez des GeoTIFF / ASC RGE ALTI ou --manifest. "
            "Voir https://geoservices.ign.fr/rgealti et l'API "
            "https://data.geopf.fr/telechargement/resource/RGEALTI"
        )

    print(f"Recadrage de {len(sources)} dalles → grille {columns}×{rows}…", flush=True)
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
        print(f"GDAL a échoué ({exc.returncode})", file=sys.stderr)
        raise SystemExit(exc.returncode) from exc
