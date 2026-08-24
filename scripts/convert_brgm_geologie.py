#!/usr/bin/env python3
"""Convert BRGM Charm-50 S_FGEOL shapefiles (Lambert-93) to WGS84 GeoJSONL with substrate class."""

from __future__ import annotations

import json
import re
import sys
import zipfile
from pathlib import Path

try:
    import shapefile
    from pyproj import Transformer
except ImportError:
    print("Installez pyshp et pyproj : pip install pyshp pyproj", file=sys.stderr)
    sys.exit(1)

# Keep in sync with App\Domain\Terrain\Substrate::fromDescription
UNKNOWN, CALCAREOUS, SILICEOUS, MIXED = 0, 1, 2, 3

CALC_RE = re.compile(
    r"calcaire|calcaires|calcar|dolomie|dolomies|dolomit|craie|urgonien|cargneule|calcschiste|lumachelle",
    re.I,
)
MIXED_RE = re.compile(r"marne|marnes|marno|marneux|terres noires", re.I)
SIL_RE = re.compile(
    r"granite|granites|gneiss|schiste|schistes|quartzite|micaschiste|amphibolite|migmatite|"
    r"basalte|and[eé]site|rhyolite|spilite|cristal|cristallo|leptynite|arkose|"
    r"gr[eè]s|gr[eé]seux|sable|sables|sablo|conglo(?:mérat|merat)|molasse|flysch",
    re.I,
)


def classify(descr: str) -> int:
    text = (descr or "").strip()
    if not text:
        return UNKNOWN
    if CALC_RE.search(text):
        return CALCAREOUS
    if MIXED_RE.search(text):
        return MIXED
    if SIL_RE.search(text):
        return SILICEOUS
    return UNKNOWN


def project_ring(ring, transformer):
    xs, ys = zip(*ring)
    lon, lat = transformer.transform(xs, ys)
    return [[float(x), float(y)] for x, y in zip(lon, lat)]


def shape_to_geometry(shape, transformer):
    parts = list(shape.parts) + [len(shape.points)]
    rings = []
    for i in range(len(parts) - 1):
        ring = shape.points[parts[i] : parts[i + 1]]
        if len(ring) >= 4:
            rings.append(project_ring(ring, transformer))
    if not rings:
        return None
    if len(rings) == 1:
        return {"type": "Polygon", "coordinates": rings}
    return {"type": "MultiPolygon", "coordinates": [[r] for r in rings]}


def convert_shapefile(shp_path: Path, out_fh, transformer) -> int:
    reader = shapefile.Reader(str(shp_path), encoding="latin1")
    fields = [f[0] for f in reader.fields[1:]]
    try:
        descr_idx = fields.index("DESCR")
    except ValueError:
        raise SystemExit(f"DESCR absent dans {shp_path}: {fields}")

    count = 0
    for sr in reader.iterShapeRecords():
        geom = shape_to_geometry(sr.shape, transformer)
        if geom is None:
            continue
        descr = sr.record[descr_idx]
        descr = "" if descr is None else str(descr).strip()
        feature = {
            "type": "Feature",
            "properties": {"descr": descr, "substrate": classify(descr)},
            "geometry": geom,
        }
        out_fh.write(json.dumps(feature, ensure_ascii=False, separators=(",", ":")) + "\n")
        count += 1
        if count % 20000 == 0:
            print(f"  … {count} polygones ({shp_path.name})", flush=True)
    return count


def main() -> None:
    if len(sys.argv) < 3:
        print(
            "Usage: convert_brgm_geologie.py <sortie.geojsonl> <zip_ou_dossier>…",
            file=sys.stderr,
        )
        sys.exit(1)

    out = Path(sys.argv[1])
    out.parent.mkdir(parents=True, exist_ok=True)
    transformer = Transformer.from_crs("EPSG:2154", "EPSG:4326", always_xy=True)

    total = 0
    with out.open("w", encoding="utf-8") as fh:
        for raw in sys.argv[2:]:
            path = Path(raw)
            shps: list[Path] = []
            if path.is_file() and path.suffix.lower() == ".zip":
                extract = path.parent / f".extract_{path.stem}"
                extract.mkdir(exist_ok=True)
                with zipfile.ZipFile(path) as zf:
                    zf.extractall(extract)
                shps = sorted(extract.rglob("*_S_FGEOL_2154.shp"))
            elif path.is_dir():
                shps = sorted(path.rglob("*_S_FGEOL_2154.shp"))
            elif path.suffix.lower() == ".shp":
                shps = [path]
            else:
                print(f"Ignoré : {path}", file=sys.stderr)
                continue

            if not shps:
                print(f"Aucun S_FGEOL dans {path}", file=sys.stderr)
                continue

            for shp in shps:
                print(f"Conversion de {shp}…", flush=True)
                total += convert_shapefile(shp, fh, transformer)

    print(f"Géologie prête : {out} ({out.stat().st_size} octets, {total} polygones)")


if __name__ == "__main__":
    main()
