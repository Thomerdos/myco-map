#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"

if command -v composer >/dev/null 2>&1; then
  COMPOSER=(composer)
elif [[ -f "${ROOT}/composer.phar" ]]; then
  COMPOSER=(php "${ROOT}/composer.phar")
else
  COMPOSER=()
fi

require_composer() {
  if [[ ${#COMPOSER[@]} -eq 0 ]]; then
    echo "Composer est introuvable. Installez-le : https://getcomposer.org/download/" >&2
    exit 1
  fi
}

ensure_env() {
  if [[ ! -f "${ROOT}/backend/.env" ]]; then
    cp "${ROOT}/backend/.env.example" "${ROOT}/backend/.env"
    echo "backend/.env créé depuis .env.example"
  fi
}

install_all() {
  require_composer
  ensure_env
  (cd "${ROOT}/backend" && "${COMPOSER[@]}" install --no-interaction)
  (cd "${ROOT}/frontend" && npm install)
}

precompute() {
  ensure_env
  if php -m 2>/dev/null | grep -qi '^pdo_sqlite$'; then
    (cd "${ROOT}/backend" && php bin/console app:precompute "$@")
    return
  fi
  if command -v docker >/dev/null 2>&1; then
    echo "Extension PHP pdo_sqlite absente — précalcul via Docker."
    (cd "${ROOT}" && docker compose run --rm --no-deps backend php bin/console app:precompute "$@")
    return
  fi
  echo "pdo_sqlite est requis. Installez php-sqlite3 (sudo apt install php-sqlite3) ou Docker." >&2
  exit 1
}

restore_data() {
  local archive="${ROOT}/data/myco-terrain-50m.sqlite.gz"
  local target="${ROOT}/backend/var/data/myco.sqlite"

  if [[ ! -f "${archive}" ]]; then
    echo "Archive introuvable : ${archive}" >&2
    echo "Lancez ./dev.sh precompute pour générer la base depuis les sources." >&2
    exit 1
  fi

  ensure_env
  mkdir -p "$(dirname "${target}")"
  rm -f "${target}" "${target}-wal" "${target}-shm"

  # Décompression vers un fichier temporaire : une erreur ne détruit pas la base existante.
  local temporary
  temporary="$(mktemp "${target}.XXXXXX")"
  if ! gunzip -c "${archive}" > "${temporary}"; then
    rm -f "${temporary}"
    echo "Décompression échouée, base inchangée." >&2
    exit 1
  fi
  mv "${temporary}" "${target}"

  echo "Base restaurée : ${target} ($(du -h "${target}" | cut -f1))"
}

export_data() {
  local source="${ROOT}/backend/var/data/myco.sqlite"
  local archive="${ROOT}/data/myco-terrain-50m.sqlite.gz"

  if [[ ! -f "${source}" ]]; then
    echo "Aucune base à exporter. Lancez ./dev.sh precompute d'abord." >&2
    exit 1
  fi

  mkdir -p "$(dirname "${archive}")"
  gzip -c "${source}" > "${archive}"
  echo "Archive écrite : ${archive} ($(du -h "${archive}" | cut -f1))"
  command -v sha256sum >/dev/null 2>&1 && sha256sum "${archive}"
}

# Converts IGN BD Forêt V2 deliveries into the newline-delimited WGS84 GeoJSON the
# BdForetLandCover adapter streams. Accepts shapefiles, GeoPackages, or IGN .7z/.zip
# archives for one or more départements; IGN ships Lambert-93, hence the reprojection.
bdforet() {
  local target="${ROOT}/backend/var/bdforet/formation-vegetale.geojsonl"
  local extract_root="${ROOT}/backend/var/bdforet/extract"
  local -a shapefiles=()

  if [[ $# -eq 0 ]]; then
    echo "Usage: ./dev.sh bdforet <FORMATION_VEGETALE.shp|.gpkg|.7z|.zip> [autres…]" >&2
    echo >&2
    echo "Téléchargez BD Forêt V2 par département sur https://geoservices.ign.fr/bdforet" >&2
    echo "(gratuit, Licence Ouverte). Pour cette zone : Isère, plus Drôme et Savoie" >&2
    echo "si vous voulez couvrir les franges du Vercors et de Belledonne." >&2
    exit 1
  fi

  local ogr2ogr_cmd=()
  if command -v ogr2ogr >/dev/null 2>&1; then
    ogr2ogr_cmd=(ogr2ogr)
  elif command -v docker >/dev/null 2>&1; then
    echo "ogr2ogr local introuvable — utilisation de l'image osgeo/gdal."
    ogr2ogr_cmd=(
      docker run --rm
      -v "${ROOT}/backend/var/bdforet:/bdforet"
      ghcr.io/osgeo/gdal:ubuntu-small-latest
      ogr2ogr
    )
  else
    echo "ogr2ogr est requis pour la conversion. Installez GDAL ou Docker :" >&2
    echo "  Debian/Ubuntu : sudo apt install gdal-bin" >&2
    echo "  macOS         : brew install gdal" >&2
    exit 1
  fi

  mkdir -p "$(dirname "${target}")" "${extract_root}"
  rm -f "${target}"

  local source
  for source in "$@"; do
    if [[ ! -e "${source}" ]]; then
      echo "Introuvable : ${source}" >&2
      exit 1
    fi

    case "${source}" in
      *.7z|*.zip|*.ZIP)
        local archive_name
        archive_name="$(basename "${source}")"
        local dest="${extract_root}/${archive_name%.*}"
        echo "Extraction de ${source}…"
        rm -rf "${dest}"
        mkdir -p "${dest}"
        if command -v 7z >/dev/null 2>&1; then
          7z x -y -o"${dest}" "${source}" >/dev/null
        elif command -v 7za >/dev/null 2>&1; then
          7za x -y -o"${dest}" "${source}" >/dev/null
        elif [[ "${source}" == *.zip || "${source}" == *.ZIP ]]; then
          unzip -q -o "${source}" -d "${dest}"
        elif command -v docker >/dev/null 2>&1; then
          docker run --rm \
            -v "$(cd "$(dirname "${source}")" && pwd)":/in:ro \
            -v "${dest}":/out \
            alpine:3.20 \
            sh -c "apk add --no-cache p7zip >/dev/null && 7z x -y -o/out \"/in/$(basename "${source}")\" >/dev/null"
        else
          echo "p7zip (7z) est requis pour extraire ${source}." >&2
          echo "  Debian/Ubuntu : sudo apt install p7zip-full" >&2
          exit 1
        fi
        local found
        found="$(find "${dest}" -type f -iname 'FORMATION_VEGETALE.shp' | head -n 1 || true)"
        if [[ -z "${found}" ]]; then
          echo "FORMATION_VEGETALE.shp introuvable dans ${source}" >&2
          exit 1
        fi
        shapefiles+=("${found}")
        ;;
      *)
        shapefiles+=("${source}")
        ;;
    esac
  done

  local shp
  for shp in "${shapefiles[@]}"; do
    echo "Conversion de ${shp}…"
    if [[ "${ogr2ogr_cmd[0]}" == docker ]]; then
      # Paths inside the container are under /bdforet (mounted from backend/var/bdforet).
      local rel_shp="${shp#"${ROOT}/backend/var/bdforet/"}"
      local rel_target="formation-vegetale.geojsonl"
      if [[ "${shp}" == "${rel_shp}" ]]; then
        echo "Le shapefile doit être sous backend/var/bdforet/ pour la conversion Docker." >&2
        echo "Passez l'archive .7z/.zip, ou installez gdal-bin localement." >&2
        exit 1
      fi
      docker run --rm \
        -v "${ROOT}/backend/var/bdforet:/bdforet" \
        ghcr.io/osgeo/gdal:ubuntu-small-latest \
        ogr2ogr -f GeoJSONSeq -append -t_srs EPSG:4326 \
          -select CODE_TFV \
          -nlt PROMOTE_TO_MULTI \
          "/bdforet/${rel_target}" "/bdforet/${rel_shp}"
    else
      ogr2ogr -f GeoJSONSeq -append -t_srs EPSG:4326 \
        -select CODE_TFV \
        -nlt PROMOTE_TO_MULTI \
        "${target}" "${shp}"
    fi
  done

  echo "BD Forêt prête : ${target} ($(du -h "${target}" | cut -f1), $(wc -l < "${target}") polygones)"
  echo "Relancez ./dev.sh precompute (ou docker compose + precompute) pour reconstruire la base avec ce couvert."
}

backend() {
  ensure_env
  if [[ ! -d "${ROOT}/backend/vendor" ]]; then
    require_composer
    (cd "${ROOT}/backend" && "${COMPOSER[@]}" install --no-interaction)
  fi
  echo "API : http://127.0.0.1:8765"
  (cd "${ROOT}/backend" && php -S 127.0.0.1:8765 -t public)
}

frontend() {
  if [[ ! -d "${ROOT}/frontend/node_modules" ]]; then
    (cd "${ROOT}/frontend" && npm install)
  fi
  echo "Interface : http://127.0.0.1:43123"
  (cd "${ROOT}/frontend" && npm run dev -- --host 0.0.0.0 --port 43123)
}

docker_up() {
  if ! command -v docker >/dev/null 2>&1; then
    echo "Docker est introuvable." >&2
    exit 1
  fi
  echo "API : http://127.0.0.1:8765"
  echo "Interface : http://127.0.0.1:43123"
  (cd "${ROOT}" && docker compose up --build)
}

# Converts BRGM Charm-50 S_FGEOL shapefiles (Lambert-93 ZIPs) to WGS84 GeoJSONL
# with a classified substrate code for the geology criterion.
geologie() {
  local target="${ROOT}/backend/var/geologie/formations.geojsonl"
  local source_dir="${ROOT}/backend/var/geologie/source"
  local -a inputs=()

  if [[ $# -gt 0 ]]; then
    inputs=("$@")
  elif [[ -d "${source_dir}" ]]; then
    while IFS= read -r -d '' zip; do
      inputs+=("${zip}")
    done < <(find -L "${source_dir}" -maxdepth 1 -name 'GEO050K_HARM_*.zip' -print0 | sort -z)
  fi

  if [[ ${#inputs[@]} -eq 0 ]]; then
    echo "Usage: ./dev.sh geologie [GEO050K_HARM_0xx.zip…]" >&2
    echo >&2
    echo "Placez les ZIP Charm-50 (Isère, Drôme, Savoie…) dans backend/var/geologie/source/" >&2
    echo "ou passez leurs chemins en arguments." >&2
    echo "Téléchargement : http://infoterre.brgm.fr/telechargements/BDCharm50/" >&2
    exit 1
  fi

  mkdir -p "$(dirname "${target}")"
  python3 "${ROOT}/scripts/convert_brgm_geologie.py" "${target}" "${inputs[@]}"
  echo "Relancez ./dev.sh precompute (ou docker compose exec backend …) pour intégrer le substrat."
}

# Downloads Copernicus HRL Tree Cover Density via CDSE OData (or converts local GeoTIFFs)
# onto the 50 m study lattice.
tcd() {
  local prefix="${ROOT}/backend/var/tcd/canopy-cover"
  local source_dir="${ROOT}/backend/var/tcd/source"
  local manifest=""
  local -a sources=()

  if [[ $# -eq 0 ]]; then
    python3 "${ROOT}/scripts/fetch_tcd.py" "${source_dir}"
    manifest="${source_dir}/manifest.json"
    if [[ ! -f "${manifest}" ]]; then
      echo "Téléchargement TCD incomplet (manifeste absent)." >&2
      exit 1
    fi
  else
    sources=("$@")
    local source
    for source in "${sources[@]}"; do
      if [[ ! -f "${source}" ]]; then
        echo "Introuvable : ${source}" >&2
        echo >&2
        echo "Sans argument, ./dev.sh tcd télécharge les tuiles 10 m via l'API OData" >&2
        echo "Copernicus Data Space (compte sur https://dataspace.copernicus.eu," >&2
        echo "variables CDSE_USERNAME / CDSE_PASSWORD ou CDSE_REFRESH_TOKEN)." >&2
        exit 1
      fi
    done
    local sibling
    sibling="$(cd "$(dirname "${sources[0]}")" && pwd)/manifest.json"
    if [[ -f "${sibling}" ]]; then
      manifest="${sibling}"
    fi
  fi

  mkdir -p "$(dirname "${prefix}")"
  convert_tcd "${prefix}" "${manifest}" "${sources[@]}"
  echo "Relancez ./dev.sh precompute pour écrire la colonne canopy_cover."
}

convert_tcd() {
  local prefix="$1"
  local manifest="$2"
  shift 2
  local -a sources=("$@")
  local -a extra=()
  if [[ -n "${manifest}" ]]; then
    extra+=(--manifest "${manifest}")
  fi

  if command -v gdalwarp >/dev/null 2>&1; then
    python3 "${ROOT}/scripts/convert_tcd.py" "${prefix}" "${extra[@]}" "${sources[@]}"
    return
  fi

  if ! command -v docker >/dev/null 2>&1; then
    echo "gdalwarp est requis pour la conversion. Installez GDAL ou Docker :" >&2
    echo "  Debian/Ubuntu : sudo apt install gdal-bin python3-gdal" >&2
    echo "  macOS         : brew install gdal" >&2
    exit 1
  fi

  echo "gdalwarp local introuvable — utilisation de l'image osgeo/gdal."
  local staging="${ROOT}/backend/var/tcd/_inputs"
  rm -rf "${staging}"
  mkdir -p "${staging}"
  local src
  for src in "${sources[@]}"; do
    ln "${src}" "${staging}/$(basename "${src}")" 2>/dev/null || cp "${src}" "${staging}/$(basename "${src}")"
  done
  local -a docker_args=(/tcd/canopy-cover)
  if [[ -n "${manifest}" ]]; then
    python3 - "${manifest}" "${staging}/manifest.json" <<'PY'
from pathlib import Path
import json
import shutil
import sys

src, dest = Path(sys.argv[1]), Path(sys.argv[2])
payload = json.loads(src.read_text(encoding="utf-8"))
parent = src.parent
for product in payload.get("products") or []:
    name = product.get("path") or product.get("name")
    if not name:
        continue
    tile = Path(name)
    if not tile.is_absolute():
        tile = parent / tile
    if not tile.suffix:
        tile = tile.with_suffix(".tif")
    if not tile.is_file():
        continue
    target = dest.parent / tile.name
    if not target.exists():
        try:
            target.hardlink_to(tile)
        except OSError:
            shutil.copy2(tile, target)
    product["path"] = tile.name
dest.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
PY
    docker_args+=(--manifest /tcd/_inputs/manifest.json)
  fi
  for src in "${sources[@]}"; do
    docker_args+=("/tcd/_inputs/$(basename "${src}")")
  done
  docker run --rm \
    -v "${ROOT}/scripts:/scripts:ro" \
    -v "${ROOT}/backend/var/tcd:/tcd" \
    ghcr.io/osgeo/gdal:ubuntu-small-latest \
    python3 /scripts/convert_tcd.py "${docker_args[@]}"
}

case "${1:-}" in
  install) install_all ;;
  precompute) shift; precompute "$@" ;;
  restore-data) restore_data ;;
  export-data) export_data ;;
  bdforet) shift; bdforet "$@" ;;
  geologie) shift; geologie "$@" ;;
  tcd) shift; tcd "$@" ;;
  backend) backend ;;
  frontend) frontend ;;
  docker) docker_up ;;
  *)
    echo "Usage: ./dev.sh [install|restore-data|precompute|export-data|bdforet|geologie|tcd|backend|frontend|docker]"
    echo
    echo "  install       Installe les dépendances PHP et JS"
    echo "  restore-data  Restaure la base précalculée depuis data/"
    echo "  precompute    Recalcule la base depuis les sources distantes"
    echo "  export-data   Réexporte la base vers data/ pour publication"
    echo "  bdforet       Convertit BD Forêt V2 pour un couvert forestier précis"
    echo "  geologie      Convertit BRGM Charm-50 pour le substrat"
    echo "  tcd           Télécharge / convertit Copernicus Tree Cover Density (0–100 %)"
    echo "  backend       Démarre l'API sur http://127.0.0.1:8765"
    echo "  frontend      Démarre l'interface sur http://127.0.0.1:43123"
    echo "  docker        Démarre API + interface via Docker Compose"
    exit 1
    ;;
esac
