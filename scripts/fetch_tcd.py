#!/usr/bin/env python3
"""Download Copernicus HRL Tree Cover Density tiles via the CDSE OData API.

Writes GeoTIFF tiles and a manifest.json under the given directory.
Keep the study bounds in sync with convert_tcd.py and App\\Domain\\Geo\\Grid.
"""

from __future__ import annotations

import getpass
import json
import os
import sys
import zipfile
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urljoin, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener, urlopen

# services.yaml app.area.*
SOUTH, WEST, NORTH, EAST = 44.72, 5.38, 45.45, 6.30

CATALOGUE = "https://catalogue.dataspace.copernicus.eu/odata/v1/Products"
DOWNLOAD = "https://download.dataspace.copernicus.eu/odata/v1/Products"
TOKEN_URL = (
    "https://identity.dataspace.copernicus.eu/auth/realms/CDSE"
    "/protocol/openid-connect/token"
)
DATASET_ID = "clms_vlcc_tree-cover-density_europe_10m_yearly_v1"
CLIENT_ID = "cdse-public"
ACCOUNT_URL = "https://dataspace.copernicus.eu"
CHUNK = 1 << 20
MAX_REDIRECTS = 12

TIFF_MAGIC = (b"II*\x00", b"MM\x00*")
ZIP_MAGIC = b"PK"


class _NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args: object, **kwargs: object) -> None:
        return None

    def http_error_302(self, req, fp, code, msg, headers):
        raise HTTPError(req.full_url, code, msg, headers, fp)

    http_error_301 = http_error_303 = http_error_307 = http_error_308 = http_error_302


_DOWNLOADER = build_opener(_NoRedirect())


def polygon_wkt() -> str:
    return (
        f"POLYGON(({WEST} {SOUTH},{EAST} {SOUTH},"
        f"{EAST} {NORTH},{WEST} {NORTH},{WEST} {SOUTH}))"
    )


def catalogue_url() -> str:
    filt = (
        "Collection/Name eq 'CLMS' and "
        "Attributes/OData.CSC.StringAttribute/any("
        "att:att/Name eq 'datasetIdentifier' and "
        f"att/OData.CSC.StringAttribute/Value eq '{DATASET_ID}') and "
        f"OData.CSC.Intersects(area=geography'SRID=4326;{polygon_wkt()}')"
    )
    query = urlencode(
        {
            "$filter": filt,
            "$orderby": "ContentDate/Start desc",
            "$top": "50",
            "$select": "Id,Name,S3Path,ContentDate,ContentLength",
        }
    )
    return f"{CATALOGUE}?{query}"


def http_json(url: str, timeout: int = 60) -> dict[str, Any]:
    request = Request(url, headers={"Accept": "application/json"})
    with urlopen(request, timeout=timeout) as response:
        return json.loads(response.read().decode("utf-8"))


def list_products() -> list[dict[str, Any]]:
    url: str | None = catalogue_url()
    products: list[dict[str, Any]] = []
    while url:
        payload = http_json(url)
        products.extend(payload.get("value") or [])
        url = payload.get("@odata.nextLink")
    return products


def product_year(product: dict[str, Any]) -> int:
    start = (product.get("ContentDate") or {}).get("Start") or ""
    try:
        return int(str(start)[:4])
    except ValueError:
        raise SystemExit(f"Date de contenu illisible : {product.get('Name')}")


def select_products(products: list[dict[str, Any]], year: int | None) -> tuple[int, list[dict[str, Any]]]:
    if not products:
        raise SystemExit(
            "Aucune tuile TCD sur l'emprise. Vérifiez le catalogue CDSE."
        )
    years = sorted({product_year(item) for item in products}, reverse=True)
    chosen = year if year is not None else years[0]
    if chosen not in years:
        available = ", ".join(str(value) for value in years)
        raise SystemExit(f"Année {chosen} absente du catalogue (disponible : {available}).")
    selected = [item for item in products if product_year(item) == chosen]
    selected.sort(key=lambda item: str(item.get("Name") or ""))
    return chosen, selected


def prompt_credentials() -> tuple[str | None, str | None, str | None]:
    refresh = os.environ.get("CDSE_REFRESH_TOKEN", "").strip() or None
    username = os.environ.get("CDSE_USERNAME", "").strip() or None
    password = os.environ.get("CDSE_PASSWORD", "").strip() or None
    if refresh or (username and password):
        return username, password, refresh
    if not sys.stdin.isatty():
        raise SystemExit(
            "Compte Copernicus Data Space requis pour le téléchargement.\n"
            f"Créez-en un sur {ACCOUNT_URL} puis exportez CDSE_USERNAME et "
            "CDSE_PASSWORD (ou CDSE_REFRESH_TOKEN)."
        )
    print(f"Compte CDSE ({ACCOUNT_URL})")
    if username is None:
        username = input("Identifiant : ").strip() or None
    if username and password is None:
        password = getpass.getpass("Mot de passe : ") or None
    if not username or not password:
        raise SystemExit("Identifiant et mot de passe CDSE requis.")
    return username, password, refresh


def request_token(username: str | None, password: str | None, refresh: str | None) -> str:
    if refresh:
        body = urlencode(
            {
                "grant_type": "refresh_token",
                "refresh_token": refresh,
                "client_id": CLIENT_ID,
            }
        )
    else:
        body = urlencode(
            {
                "grant_type": "password",
                "username": username or "",
                "password": password or "",
                "client_id": CLIENT_ID,
            }
        )
    request = Request(
        TOKEN_URL,
        data=body.encode("utf-8"),
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        method="POST",
    )
    try:
        with urlopen(request, timeout=60) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except HTTPError as error:
        detail = error.read().decode("utf-8", errors="replace")[:400]
        raise SystemExit(
            f"Authentification CDSE refusée (HTTP {error.code}). {detail}\n"
            f"Compte : {ACCOUNT_URL}"
        ) from error
    token = payload.get("access_token")
    if not isinstance(token, str) or not token:
        raise SystemExit("Réponse d'authentification CDSE sans access_token.")
    return token


def origin(url: str) -> str:
    parts = urlsplit(url)
    return f"{parts.scheme}://{parts.netloc}".lower()


def follow_download(url: str, token: str):
    current = url
    headers = {"Authorization": f"Bearer {token}"}
    for _ in range(MAX_REDIRECTS):
        request = Request(current, headers=headers)
        try:
            return _DOWNLOADER.open(request, timeout=120)
        except HTTPError as error:
            if error.code not in {301, 302, 303, 307, 308}:
                raise
            location = error.headers.get("Location")
            if not location:
                raise SystemExit(f"Redirection sans Location depuis {current}") from error
            nxt = urljoin(current, location)
            if origin(nxt) != origin(current):
                headers = {}
            error.close()
            current = nxt
    raise SystemExit(f"Trop de redirections pour {url}")


def is_tiff(path: Path) -> bool:
    magic = path.read_bytes()[:4]
    return magic in TIFF_MAGIC


def is_zip(path: Path) -> bool:
    return path.read_bytes()[:2] == ZIP_MAGIC


def extract_tiffs(archive: Path, dest_dir: Path) -> list[Path]:
    extracted: list[Path] = []
    with zipfile.ZipFile(archive) as zipped:
        for info in zipped.infolist():
            name = Path(info.filename).name
            if not name.lower().endswith((".tif", ".tiff")):
                continue
            target = dest_dir / name
            target.write_bytes(zipped.read(info))
            extracted.append(target)
    return extracted


def download_product(product: dict[str, Any], dest_dir: Path, token: str) -> Path:
    product_id = product["Id"]
    name = str(product.get("Name") or product_id)
    expected = int(product.get("ContentLength") or 0)
    stem = dest_dir / name
    tif = stem.with_suffix(".tif")
    if tif.is_file() and tif.stat().st_size > 0:
        print(f"Déjà présent : {tif.name}")
        return tif

    url = f"{DOWNLOAD}({product_id})/$value"
    tmp = dest_dir / f".{name}.part"
    print(f"Téléchargement {name}" + (f" ({expected / 1e6:.1f} Mo)…" if expected else "…"))
    try:
        with follow_download(url, token) as response:
            with tmp.open("wb") as handle:
                while True:
                    chunk = response.read(CHUNK)
                    if not chunk:
                        break
                    handle.write(chunk)
    except HTTPError as error:
        tmp.unlink(missing_ok=True)
        detail = error.read().decode("utf-8", errors="replace")[:300]
        error.close()
        raise SystemExit(
            f"Téléchargement refusé pour {name} (HTTP {error.code}). {detail}"
        ) from error
    except URLError as error:
        tmp.unlink(missing_ok=True)
        raise SystemExit(f"Téléchargement interrompu pour {name} : {error}") from error

    if is_zip(tmp):
        tiffs = extract_tiffs(tmp, dest_dir)
        tmp.unlink(missing_ok=True)
        if not tiffs:
            raise SystemExit(f"ZIP sans GeoTIFF : {name}")
        if len(tiffs) == 1:
            if tiffs[0] != tif:
                tiffs[0].replace(tif)
            return tif
        return tiffs[0]

    if not is_tiff(tmp):
        tmp.unlink(missing_ok=True)
        raise SystemExit(f"Fichier ni GeoTIFF ni ZIP : {name}")
    tmp.replace(tif)
    return tif


def write_manifest(path: Path, year: int, products: list[dict[str, Any]], files: list[Path]) -> None:
    payload = {
        "datasetIdentifier": DATASET_ID,
        "year": year,
        "south": SOUTH,
        "west": WEST,
        "north": NORTH,
        "east": EAST,
        "source": "Copernicus HRL Tree Cover Density",
        "products": [
            {
                "id": item["Id"],
                "name": item.get("Name"),
                "path": files[index].name,
                "contentLength": item.get("ContentLength"),
            }
            for index, item in enumerate(products)
        ],
    }
    path.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")


def parse_year() -> int | None:
    raw = os.environ.get("TCD_YEAR", "").strip()
    if not raw:
        return None
    try:
        return int(raw)
    except ValueError:
        raise SystemExit(f"TCD_YEAR invalide : {raw}")


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: fetch_tcd.py <répertoire-destination>", file=sys.stderr)
        return 1

    dest_dir = Path(sys.argv[1])
    dest_dir.mkdir(parents=True, exist_ok=True)

    print("Catalogue OData Copernicus Data Space…")
    try:
        products = list_products()
    except (HTTPError, URLError) as error:
        raise SystemExit(f"Catalogue CDSE injoignable : {error}") from error

    year, selected = select_products(products, parse_year())
    print(f"Année {year} : {len(selected)} tuile(s) sur l'emprise.")

    username, password, refresh = prompt_credentials()
    token = request_token(username, password, refresh)

    files: list[Path] = []
    for product in selected:
        files.append(download_product(product, dest_dir, token))

    manifest = dest_dir / "manifest.json"
    write_manifest(manifest, year, selected, files)
    print(f"Manifeste : {manifest}")
    for path in files:
        print(path)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except KeyboardInterrupt:
        print("Interrompu.", file=sys.stderr)
        raise SystemExit(130)
