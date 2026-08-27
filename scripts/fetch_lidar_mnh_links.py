#!/usr/bin/env python3
"""List IGN LIDAR HD MNH 1 km tiles via Géoplateforme WFS and write GetMap URLs.

Default presets cover Chartreuse, Vercors and Belledonne (clipped to the study
area). Reuses https://data.geopf.fr/wfs … TYPENAMES=IGNF_MNH-LIDAR-HD:dalle.

  python3 scripts/fetch_lidar_mnh_links.py backend/var/lidar/source/liens-massifs.txt
  python3 scripts/download_lidar_links.py …/liens-massifs.txt …/tiles --pixel-size 40

Env / flags keep the massif boxes editable when adapting the map extent.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Iterable
from urllib.parse import urlencode
from urllib.request import Request, urlopen

USER_AGENT = "myco-map/lidar-mnh (https://github.com/Thomerdos/myco-map)"
WFS = "https://data.geopf.fr/wfs/ows"
TYPENAME = "IGNF_MNH-LIDAR-HD:dalle"
PAGE = 1000

# Study area (services.yaml) — massifs are clipped to this rectangle.
STUDY = (44.72, 5.38, 45.45, 6.30)  # south, west, north, east

# Named massifs as WGS84 (south, west, north, east). Tuned for Grenoble.
MASSIFS: dict[str, tuple[float, float, float, float]] = {
    "chartreuse": (45.20, 5.60, 45.42, 5.95),
    "vercors": (44.90, 5.38, 45.22, 5.70),
    "belledonne": (45.05, 5.90, 45.35, 6.25),
}


def clip_to_study(box: tuple[float, float, float, float]) -> tuple[float, float, float, float]:
    south, west, north, east = box
    ss, ww, nn, ee = STUDY
    return max(south, ss), max(west, ww), min(north, nn), min(east, ee)


def union_boxes(boxes: Iterable[tuple[float, float, float, float]]) -> tuple[float, float, float, float]:
    items = list(boxes)
    return (
        min(b[0] for b in items),
        min(b[1] for b in items),
        max(b[2] for b in items),
        max(b[3] for b in items),
    )


def wgs84_to_l93_bbox(box: tuple[float, float, float, float]) -> tuple[float, float, float, float]:
    from pyproj import Transformer

    south, west, north, east = box
    to_l93 = Transformer.from_crs(4326, 2154, always_xy=True)
    xs: list[float] = []
    ys: list[float] = []
    for lat in (south, north):
        for lng in (west, east):
            x, y = to_l93.transform(lng, lat)
            xs.append(x)
            ys.append(y)
    pad = 200.0
    return min(xs) - pad, min(ys) - pad, max(xs) + pad, max(ys) + pad


def http_json(url: str) -> dict:
    request = Request(url, headers={"User-Agent": USER_AGENT, "Accept": "application/json"})
    with urlopen(request, timeout=180) as response:
        return json.loads(response.read().decode("utf-8"))


def iter_tile_urls(l93_bbox: tuple[float, float, float, float]) -> list[str]:
    minx, miny, maxx, maxy = l93_bbox
    urls: list[str] = []
    start = 0
    matched: int | None = None
    while True:
        params = {
            "SERVICE": "WFS",
            "VERSION": "2.0.0",
            "REQUEST": "GetFeature",
            "TYPENAMES": TYPENAME,
            "OUTPUTFORMAT": "application/json",
            "SRSNAME": "EPSG:2154",
            "BBOX": f"{minx},{miny},{maxx},{maxy},EPSG:2154",
            "COUNT": str(PAGE),
            "STARTINDEX": str(start),
        }
        url = f"{WFS}?{urlencode(params)}"
        print(f"WFS startIndex={start}…", flush=True)
        payload = http_json(url)
        if matched is None:
            matched = int(payload.get("numberMatched") or payload.get("totalFeatures") or 0)
            print(f"  {matched} dalles MNH dans l'emprise", flush=True)
        features = payload.get("features") or []
        if not features:
            break
        for feature in features:
            props = feature.get("properties") or {}
            href = props.get("url")
            if href and str(href).startswith("http"):
                urls.append(str(href).strip())
        start += len(features)
        if matched is not None and start >= matched:
            break
        if len(features) < PAGE:
            break

    # Stable unique order
    return list(dict.fromkeys(urls))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("out_file", type=Path, help="Fichier de liens GetMap (un URL / ligne)")
    parser.add_argument(
        "--massifs",
        default="chartreuse,vercors,belledonne",
        help=f"Liste séparée par des virgules ({', '.join(MASSIFS)}) ou 'study'",
    )
    parser.add_argument(
        "--merge",
        type=Path,
        default=None,
        help="Fusionner avec un fichier de liens existant (URLs uniques)",
    )
    args = parser.parse_args()

    names = [n.strip().lower() for n in args.massifs.split(",") if n.strip()]
    boxes: list[tuple[float, float, float, float]] = []
    for name in names:
        if name == "study":
            boxes.append(STUDY)
            continue
        if name not in MASSIFS:
            print(f"Massif inconnu : {name} (connus : {', '.join(MASSIFS)}, study)", file=sys.stderr)
            return 1
        boxes.append(clip_to_study(MASSIFS[name]))

    wgs = union_boxes(boxes)
    l93 = wgs84_to_l93_bbox(wgs)
    print(
        f"Emprise WGS84 {wgs[0]:.3f},{wgs[1]:.3f} → {wgs[2]:.3f},{wgs[3]:.3f} "
        f"(L93 {l93[0]:.0f},{l93[1]:.0f} → {l93[2]:.0f},{l93[3]:.0f})",
        flush=True,
    )

    urls = iter_tile_urls(l93)
    if args.merge and args.merge.is_file():
        extra = [
            line.strip()
            for line in args.merge.read_text(encoding="utf-8", errors="replace").splitlines()
            if line.strip().startswith("http")
        ]
        before = len(urls)
        urls = list(dict.fromkeys([*urls, *extra]))
        print(f"Fusion +{len(urls) - before} URLs depuis {args.merge}", flush=True)

    args.out_file.parent.mkdir(parents=True, exist_ok=True)
    args.out_file.write_text("\n".join(urls) + ("\n" if urls else ""), encoding="utf-8")
    print(f"Écrit {len(urls)} liens → {args.out_file}")
    return 0 if urls else 1


if __name__ == "__main__":
    raise SystemExit(main())
