#!/usr/bin/env python3
"""Resolve the latest EcoDataCube / AI4SoilHealth soil pH (H₂O) COG for the study area.

Writes a small manifest.json under the given directory (URL + period + depth).
Keep the study bounds in sync with convert_soil_ph.py and App\\Domain\\Geo\\Grid.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any
from urllib.parse import urljoin
from urllib.request import urlopen

# services.yaml app.area.*
SOUTH, WEST, NORTH, EAST = 44.72, 5.38, 45.45, 6.30

STAC_COLLECTION = (
    "https://s3.ecodatacube.eu/arco/stac/"
    "ph.h2o_iso.10390.2021.index/collection.json"
)
# Mean prediction, topsoil (0–20 cm) — most relevant for ectomycorrhizal fruiting.
ASSET_KEY = "ph.h2o_iso.10390.2021.index_m_30m_b0cm..20cm"
SOURCE = "EcoDataCube / AI4SoilHealth soil pH H2O (ISO 10390:2021), 30 m"


def http_json(url: str) -> dict[str, Any]:
    with urlopen(url, timeout=90) as response:
        return json.loads(response.read().decode("utf-8"))


def list_items(collection: dict[str, Any], collection_url: str) -> list[str]:
    urls: list[str] = []
    for link in collection.get("links") or []:
        if link.get("rel") == "item" and link.get("href"):
            urls.append(urljoin(collection_url, str(link["href"])))
    return urls


def pick_latest(item_urls: list[str]) -> dict[str, Any]:
    if not item_urls:
        raise SystemExit("Collection STAC sans items pH.")
    best: dict[str, Any] | None = None
    best_end = ""
    for url in item_urls:
        item = http_json(url)
        end = str((item.get("properties") or {}).get("end_datetime") or "")
        if end >= best_end:
            best_end = end
            best = item
    assert best is not None
    return best


def main() -> int:
    if len(sys.argv) < 2:
        print("Usage: fetch_soil_ph.py <dossier-sortie>", file=sys.stderr)
        return 1

    out_dir = Path(sys.argv[1])
    out_dir.mkdir(parents=True, exist_ok=True)

    print("Catalogue STAC EcoDataCube (pH H₂O)…", flush=True)
    collection = http_json(STAC_COLLECTION)
    item = pick_latest(list_items(collection, STAC_COLLECTION))
    assets = item.get("assets") or {}
    asset = assets.get(ASSET_KEY)
    if not isinstance(asset, dict) or not asset.get("href"):
        raise SystemExit(f"Asset introuvable : {ASSET_KEY}")

    props = item.get("properties") or {}
    manifest = {
        "source": SOURCE,
        "collection": STAC_COLLECTION,
        "itemId": item.get("id"),
        "assetKey": ASSET_KEY,
        "href": asset["href"],
        "start": props.get("start_datetime"),
        "end": props.get("end_datetime"),
        "depth": "0-20cm",
        "gsdMeters": 30,
        "scale": 0.1,
        "nodata": 255,
        "crs": "EPSG:3035",
        "bounds": {"south": SOUTH, "west": WEST, "north": NORTH, "east": EAST},
    }
    path = out_dir / "manifest.json"
    path.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    print(f"Manifeste : {path}")
    print(f"Période {manifest['start']} → {manifest['end']}")
    print(f"COG : {manifest['href']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
