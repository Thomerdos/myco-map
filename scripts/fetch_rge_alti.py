#!/usr/bin/env python3
"""Download IGN RGE ALTI MNT packages via the Géoplateforme Atom API.

Lists https://data.geopf.fr/telechargement/resource/RGEALTI, picks the latest
ASC package per département (default 5 m, D026 / D038 / D073), downloads the
.7z archives, and writes manifest.json for ./dev.sh rgealti.

Keep SOUTH/WEST/NORTH/EAST and the zone list in sync with convert_rge_alti.py,
App\\Domain\\Geo\\Grid / services.yaml when adapting the study area.

Env:
  RGE_ALTI_ZONES        Comma-separated gpf zones (default D026,D038,D073)
  RGE_ALTI_RESOLUTION   5M (default) or 1M — 1 m is multi-GB per département
"""

from __future__ import annotations

import json
import os
import sys
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

# services.yaml app.area.*
SOUTH, WEST, NORTH, EAST = 44.72, 5.38, 45.45, 6.30

RESOURCE = "RGEALTI"
API = "https://data.geopf.fr/telechargement"
ATOM_NS = {"atom": "http://www.w3.org/2005/Atom", "gpf": "https://data.geopf.fr/annexes/ressources/xsd/gpf_dl.xsd"}
USER_AGENT = "myco-map/rge-alti (https://github.com/Thomerdos/myco-map)"
CHUNK = 1 << 20
PAGE_SIZE = 50


def http_bytes(url: str, timeout: int = 120) -> bytes:
    request = Request(url, headers={"User-Agent": USER_AGENT, "Accept": "*/*"})
    with urlopen(request, timeout=timeout) as response:
        return response.read()


def http_text(url: str, timeout: int = 120) -> str:
    return http_bytes(url, timeout=timeout).decode("utf-8")


def zones_wanted() -> list[str]:
    raw = os.environ.get("RGE_ALTI_ZONES") or "D026,D038,D073"
    return [z.strip().upper() for z in raw.split(",") if z.strip()]


def resolution_wanted() -> str:
    raw = (os.environ.get("RGE_ALTI_RESOLUTION") or "5M").strip().upper()
    if raw not in {"5M", "1M"}:
        raise SystemExit(f"RGE_ALTI_RESOLUTION invalide ({raw}) — utiliser 5M ou 1M")
    return raw


def list_packages(resolution: str, zones: list[str]) -> dict[str, dict[str, str]]:
    """Return zone → {title, href} for the latest matching ASC LAMB93 package."""
    wanted = set(zones)
    best: dict[str, dict[str, str]] = {}
    page = 1
    pages = 1
    while page <= pages:
        url = f"{API}/resource/{RESOURCE}?limit={PAGE_SIZE}&page={page}"
        print(f"Catalogue RGE ALTI (page {page})…", flush=True)
        root = ET.fromstring(http_text(url))
        pages = int(root.attrib.get("{https://data.geopf.fr/annexes/ressources/xsd/gpf_dl.xsd}pagecount") or pages)
        for entry in root.findall("atom:entry", ATOM_NS):
            title_el = entry.find("atom:title", ATOM_NS)
            title = (title_el.text or "").strip() if title_el is not None else ""
            if f"_{resolution}_ASC_LAMB93" not in title:
                continue
            entry_zones = [
                z.attrib.get("term", "")
                for z in entry.findall("gpf:zone", ATOM_NS)
            ]
            link = entry.find('atom:link[@rel="alternate"]', ATOM_NS)
            if link is None:
                link = entry.find("atom:link", ATOM_NS)
            href = link.attrib.get("href", "") if link is not None else ""
            if not href:
                continue
            for zone in entry_zones:
                if zone not in wanted:
                    continue
                previous = best.get(zone)
                # Titles end with YYYY-MM-DD — lexicographic max ≈ latest edition.
                if previous is None or title > previous["title"]:
                    best[zone] = {"title": title, "href": href, "zone": zone}
        page += 1

    missing = sorted(wanted - set(best))
    if missing:
        raise SystemExit(
            "Packages RGE ALTI introuvables pour : "
            + ", ".join(missing)
            + f" (résolution {resolution}). Vérifiez RGE_ALTI_ZONES."
        )
    return best


def list_downloads(package_url: str) -> list[dict[str, Any]]:
    """Files under a package (usually one .7z for 5 m, several parts for 1 m)."""
    root = ET.fromstring(http_text(f"{package_url}?limit={PAGE_SIZE}"))
    length_attr = "{https://data.geopf.fr/annexes/ressources/xsd/gpf_dl.xsd}length"
    files: list[dict[str, Any]] = []
    for entry in root.findall("atom:entry", ATOM_NS):
        title_el = entry.find("atom:title", ATOM_NS)
        title = (title_el.text or "").strip() if title_el is not None else ""
        for link in entry.findall("atom:link", ATOM_NS):
            href = link.attrib.get("href", "")
            if "/telechargement/download/" not in href:
                continue
            raw_len = link.attrib.get(length_attr) or link.attrib.get("length")
            length = int(raw_len) if raw_len and str(raw_len).isdigit() else None
            name = title if title else href.rsplit("/", 1)[-1]
            if not name.endswith((".7z", ".zip", ".tif", ".tiff", ".asc")):
                name = href.rsplit("/", 1)[-1]
            files.append({"name": name, "url": href, "bytes": length})
            break
    if not files:
        raise SystemExit(f"Aucun fichier téléchargeable sous {package_url}")
    return files


def download_file(url: str, dest: Path, expected: int | None) -> None:
    dest.parent.mkdir(parents=True, exist_ok=True)
    if dest.is_file() and expected and dest.stat().st_size == expected:
        print(f"  déjà présent : {dest.name} ({expected // (1 << 20)} Mo)", flush=True)
        return
    if dest.is_file() and expected is None and dest.stat().st_size > 0:
        print(f"  déjà présent : {dest.name}", flush=True)
        return

    tmp = dest.with_suffix(dest.suffix + ".part")
    start = tmp.stat().st_size if tmp.is_file() else 0
    headers = {"User-Agent": USER_AGENT, "Accept": "*/*"}
    if start > 0:
        headers["Range"] = f"bytes={start}-"
        print(f"  reprise {dest.name} @ {start // (1 << 20)} Mo…", flush=True)
    else:
        print(f"  téléchargement {dest.name}…", flush=True)

    request = Request(url, headers=headers)
    try:
        with urlopen(request, timeout=300) as response:
            mode = "ab" if start > 0 and response.status == 206 else "wb"
            if mode == "wb":
                start = 0
            written = start
            with tmp.open(mode) as out:
                while True:
                    chunk = response.read(CHUNK)
                    if not chunk:
                        break
                    out.write(chunk)
                    written += len(chunk)
                    if expected and written % (32 << 20) < CHUNK:
                        print(f"    {written // (1 << 20)} / {expected // (1 << 20)} Mo", flush=True)
    except HTTPError as exc:
        raise SystemExit(f"HTTP {exc.code} pour {url}") from exc
    except URLError as exc:
        raise SystemExit(f"Réseau : {exc}") from exc

    if expected and tmp.stat().st_size != expected:
        raise SystemExit(
            f"Taille incorrecte pour {dest.name} : {tmp.stat().st_size} ≠ {expected}"
        )
    tmp.replace(dest)


def main() -> int:
    if len(sys.argv) < 2:
        print("Usage: fetch_rge_alti.py <dossier-sortie>", file=sys.stderr)
        return 1

    out_dir = Path(sys.argv[1])
    archives = out_dir / "archives"
    extracted = out_dir / "extracted"
    out_dir.mkdir(parents=True, exist_ok=True)
    archives.mkdir(parents=True, exist_ok=True)
    extracted.mkdir(parents=True, exist_ok=True)

    zones = zones_wanted()
    resolution = resolution_wanted()
    if resolution == "1M":
        print(
            "Attention : RGE ALTI 1 m = plusieurs Go par département. "
            "Préférez 5M pour une grille 50 m.",
            flush=True,
        )

    packages = list_packages(resolution, zones)
    products: list[dict[str, Any]] = []

    for zone in zones:
        info = packages[zone]
        print(f"Zone {zone} → {info['title']}", flush=True)
        files = list_downloads(info["href"])
        package_extract = extracted / info["title"]
        package_extract.mkdir(parents=True, exist_ok=True)
        local_files: list[str] = []
        total = 0
        for file_info in files:
            name = file_info["name"]
            # Skip tiny sidecar entries (length sometimes 411 for md5).
            if file_info["bytes"] is not None and file_info["bytes"] < 10_000:
                continue
            dest = archives / name
            download_file(file_info["url"], dest, file_info["bytes"])
            local_files.append(str(dest.relative_to(out_dir)))
            total += dest.stat().st_size
        products.append(
            {
                "zone": zone,
                "package": info["title"],
                "packageUrl": info["href"],
                "resolution": resolution,
                "path": local_files[0] if len(local_files) == 1 else local_files,
                "files": local_files,
                "extracted": str(package_extract.relative_to(out_dir)),
                "bytes": total,
            }
        )

    manifest = {
        "source": "ign-rge-alti",
        "api": f"{API}/resource/{RESOURCE}",
        "resolution": resolution,
        "zones": zones,
        "bounds": {"south": SOUTH, "west": WEST, "north": NORTH, "east": EAST},
        "note": (
            "Extraire les .7z vers extracted/<package>/ puis "
            "./dev.sh rgealti --manifest (ou ./dev.sh rgealti sans argument)."
        ),
        "products": products,
    }
    path = out_dir / "manifest.json"
    path.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    print(f"Manifeste : {path}")
    print(f"Archives : ~{sum(p['bytes'] for p in products) // (1 << 20)} Mo")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
