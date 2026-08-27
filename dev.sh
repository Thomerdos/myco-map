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

TOOLS_IMAGE="${MYCO_TOOLS_IMAGE:-myco-map-tools:local}"
GDAL_IMAGE="${MYCO_GDAL_IMAGE:-ghcr.io/osgeo/gdal:ubuntu-small-latest}"
# Match host UID so var/ files written by containers stay deletable without sudo.
DOCKER_USER="$(id -u):$(id -g)"

require_docker() {
  if ! command -v docker >/dev/null 2>&1; then
    echo "Docker est requis. Installez-le : https://docs.docker.com/get-docker/" >&2
    exit 1
  fi
}

ensure_tools_image() {
  require_docker
  if docker image inspect "${TOOLS_IMAGE}" >/dev/null 2>&1; then
    return
  fi
  echo "Construction de l'image d'outils Python (${TOOLS_IMAGE})…"
  docker build -t "${TOOLS_IMAGE}" -f "${ROOT}/scripts/Dockerfile.tools" "${ROOT}/scripts"
}

# Repo mounted at /work. Arguments are the command run inside the container.
docker_tools() {
  ensure_tools_image
  docker run --rm --user "${DOCKER_USER}" -v "${ROOT}:/work" -w /work "${TOOLS_IMAGE}" "$@"
}

# Usage: docker_gdal [docker run options…] -- command [args…]
# Options must be separated from the command by "--" so the image name is not mistaken
# for an argument (e.g. "python3").
docker_gdal() {
  require_docker
  local -a opts=(--user "${DOCKER_USER}")
  while [[ $# -gt 0 && "$1" != "--" ]]; do
    opts+=("$1")
    shift
  done
  if [[ "${1:-}" != "--" ]]; then
    echo "docker_gdal: séparez les options Docker de la commande avec --" >&2
    exit 1
  fi
  shift
  docker run --rm "${opts[@]}" "${GDAL_IMAGE}" "$@"
}

# Extract .7z/.zip into $2 using Alpine (no host p7zip/unzip).
# Resolves symlinks so archives living outside the repo (via a link) still mount.
extract_archive_docker() {
  local source="$1"
  local dest="$2"
  local real parent base
  real="$(realpath "${source}")"
  parent="$(dirname "${real}")"
  base="$(basename "${real}")"
  require_docker
  mkdir -p "${dest}"
  # apk needs root; chown so the host user can delete extracts later.
  docker run --rm \
    -v "${parent}:/in:ro" \
    -v "${dest}:/out" \
    alpine:3.20 \
    sh -c "apk add --no-cache p7zip unzip >/dev/null && case \"${base}\" in *.zip|*.ZIP) unzip -q -o \"/in/${base}\" -d /out ;; *) 7z x -y -o/out \"/in/${base}\" >/dev/null ;; esac && chown -R ${DOCKER_USER} /out"
}

# Remove a path that may contain root-owned files left by older container runs.
docker_rm_rf() {
  local path="$1"
  [[ -e "${path}" ]] || return 0
  if rm -rf "${path}" 2>/dev/null; then
    return 0
  fi
  require_docker
  local parent base
  parent="$(cd "$(dirname "${path}")" && pwd)"
  base="$(basename "${path}")"
  docker run --rm -v "${parent}:/target" alpine:3.20 rm -rf "/target/${base}"
}

install_all() {
  require_composer
  ensure_env
  (cd "${ROOT}/backend" && "${COMPOSER[@]}" install --no-interaction)
  (cd "${ROOT}/frontend" && npm install)
}

precompute() {
  ensure_env
  require_docker
  if ! docker compose version >/dev/null 2>&1; then
    echo "Docker Compose v2 est requis pour le précalcul." >&2
    exit 1
  fi
  echo "Précalcul via Docker (PHP 8.4, APP_ENV=prod)."
  # Prod + no-debug: skip Symfony profiler / verbose logging; opcache stays off in the
  # image ini but container boot and DI are still cheaper without debug dumps.
  (cd "${ROOT}" && docker compose run --rm --no-deps \
    -e APP_ENV=prod \
    -e APP_DEBUG=0 \
    backend php bin/console --env=prod --no-debug app:precompute "$@")
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

  require_docker
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
        local archive_name dest found
        archive_name="$(basename "${source}")"
        dest="${extract_root}/${archive_name%.*}"
        echo "Extraction de ${source}…"
        rm -rf "${dest}"
        extract_archive_docker "${source}" "${dest}"
        found="$(find "${dest}" -type f -iname 'FORMATION_VEGETALE.shp' | head -n 1 || true)"
        if [[ -z "${found}" ]]; then
          echo "FORMATION_VEGETALE.shp introuvable dans ${source}" >&2
          exit 1
        fi
        shapefiles+=("${found}")
        ;;
      *)
        # Copy under backend/var/bdforet so the GDAL container can see it.
        if [[ "${source}" != "${ROOT}/backend/var/bdforet/"* ]]; then
          mkdir -p "${ROOT}/backend/var/bdforet/inputs"
          cp -a "${source}" "${ROOT}/backend/var/bdforet/inputs/"
          shapefiles+=("${ROOT}/backend/var/bdforet/inputs/$(basename "${source}")")
        else
          shapefiles+=("${source}")
        fi
        ;;
    esac
  done

  local shp rel_shp
  for shp in "${shapefiles[@]}"; do
    echo "Conversion de ${shp}…"
    rel_shp="${shp#"${ROOT}/backend/var/bdforet/"}"
    if [[ "${shp}" == "${rel_shp}" ]]; then
      echo "Le shapefile doit être sous backend/var/bdforet/ pour la conversion Docker." >&2
      exit 1
    fi
    docker_gdal \
      -v "${ROOT}/backend/var/bdforet:/bdforet" \
      -- \
      ogr2ogr -f GeoJSONSeq -append -t_srs EPSG:4326 \
        -select CODE_TFV \
        -nlt PROMOTE_TO_MULTI \
        /bdforet/formation-vegetale.geojsonl "/bdforet/${rel_shp}"
  done

  echo "BD Forêt prête : ${target} ($(du -h "${target}" | cut -f1), $(wc -l < "${target}") polygones)"
  echo "Relancez ./dev.sh precompute pour reconstruire la base avec ce couvert."
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
# Inputs are staged under backend/var/geologie/_inputs so Docker sees real files even
# when source/ is a symlink to a folder outside the repo (bind mounts do not follow
# absolute symlinks that leave the mounted tree).
geologie() {
  local target="${ROOT}/backend/var/geologie/formations.geojsonl"
  local source_dir="${ROOT}/backend/var/geologie/source"
  local staging="${ROOT}/backend/var/geologie/_inputs"
  local -a inputs=()
  local -a container_inputs=()

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
    echo "(ce dossier peut être un lien symbolique) ou passez leurs chemins en arguments." >&2
    echo "Téléchargement : http://infoterre.brgm.fr/telechargements/BDCharm50/" >&2
    exit 1
  fi

  require_docker
  mkdir -p "$(dirname "${target}")"
  if [[ ! -e "${source_dir}" ]]; then
    mkdir -p "${source_dir}"
  fi
  docker_rm_rf "${staging}"
  mkdir -p "${staging}"

  local input real base
  for input in "${inputs[@]}"; do
    if [[ ! -e "${input}" ]]; then
      echo "Introuvable : ${input}" >&2
      exit 1
    fi
    real="$(realpath "${input}")"
    base="$(basename "${real}")"
    # Hardlink when possible (same filesystem); otherwise copy.
    ln "${real}" "${staging}/${base}" 2>/dev/null || cp -a "${real}" "${staging}/${base}"
    container_inputs+=("/work/backend/var/geologie/_inputs/${base}")
  done

  echo "Conversion Charm-50 via Docker…"
  docker_tools python3 scripts/convert_brgm_geologie.py \
    /work/backend/var/geologie/formations.geojsonl \
    "${container_inputs[@]}"
  # Staging was only for the Docker bind mount (and shapefile extracts beside the ZIPs).
  docker_rm_rf "${staging}"
  echo "Relancez ./dev.sh precompute pour intégrer le substrat."
}

# Downloads Copernicus HRL Tree Cover Density via CDSE OData (or converts local GeoTIFFs)
# onto the 50 m study lattice. Host needs only Docker (+ CDSE credentials in the env).
tcd() {
  local prefix="${ROOT}/backend/var/tcd/canopy-cover"
  local source_dir="${ROOT}/backend/var/tcd/source"
  local staging="${ROOT}/backend/var/tcd/_inputs"
  local -a docker_args=(/tcd/canopy-cover)

  require_docker
  mkdir -p "${source_dir}" "$(dirname "${prefix}")"

  if [[ $# -eq 0 ]]; then
    if [[ -z "${CDSE_USERNAME:-}${CDSE_PASSWORD:-}${CDSE_REFRESH_TOKEN:-}" ]]; then
      echo "Compte Copernicus Data Space requis pour le téléchargement." >&2
      echo "Exportez CDSE_USERNAME et CDSE_PASSWORD (ou CDSE_REFRESH_TOKEN), puis relancez." >&2
      echo "Inscription : https://dataspace.copernicus.eu" >&2
      exit 1
    fi
    echo "Téléchargement TCD via Docker…"
    local -a env_flags=(-e CDSE_USERNAME -e CDSE_PASSWORD -e CDSE_REFRESH_TOKEN)
    if [[ -n "${TCD_YEAR:-}" ]]; then
      env_flags+=(-e TCD_YEAR)
    fi
    ensure_tools_image
    docker run --rm --user "${DOCKER_USER}" \
      -v "${ROOT}:/work" \
      -w /work \
      "${env_flags[@]}" \
      "${TOOLS_IMAGE}" \
      python3 scripts/fetch_tcd.py /work/backend/var/tcd/source
    if [[ ! -f "${source_dir}/manifest.json" ]]; then
      echo "Téléchargement TCD incomplet (manifeste absent)." >&2
      exit 1
    fi
    docker_args+=(--manifest /tcd/source/manifest.json)
  else
    docker_rm_rf "${staging}"
    mkdir -p "${staging}"
    local source
    for source in "$@"; do
      if [[ ! -f "${source}" ]]; then
        echo "Introuvable : ${source}" >&2
        echo >&2
        echo "Sans argument, ./dev.sh tcd télécharge les tuiles 10 m via l'API OData" >&2
        echo "Copernicus Data Space (CDSE_USERNAME / CDSE_PASSWORD ou CDSE_REFRESH_TOKEN)." >&2
        exit 1
      fi
      ln "${source}" "${staging}/$(basename "${source}")" 2>/dev/null \
        || cp "${source}" "${staging}/$(basename "${source}")"
      docker_args+=("/tcd/_inputs/$(basename "${source}")")
    done
    if [[ -f "$(cd "$(dirname "${1}")" && pwd)/manifest.json" ]]; then
      cp "$(cd "$(dirname "${1}")" && pwd)/manifest.json" "${staging}/manifest.json"
      docker_args+=(--manifest /tcd/_inputs/manifest.json)
    fi
  fi

  echo "Recadrage TCD via Docker (GDAL)…"
  # Old runs may have left root-owned outputs; remove so --user can rewrite them.
  docker_rm_rf "${prefix}.bin"
  docker_rm_rf "${prefix}.json"
  docker_gdal \
    -v "${ROOT}/scripts:/scripts:ro" \
    -v "${ROOT}/backend/var/tcd:/tcd" \
    -- \
    python3 /scripts/convert_tcd.py "${docker_args[@]}"
  echo "Relancez ./dev.sh precompute pour écrire la colonne canopy_cover."
}

# Downloads EcoDataCube / AI4SoilHealth soil pH (H₂O, 30 m) via STAC and warps onto the 50 m grid.
soilph() {
  local prefix="${ROOT}/backend/var/soilph/soil-ph"
  local source_dir="${ROOT}/backend/var/soilph/source"

  require_docker
  mkdir -p "${source_dir}" "$(dirname "${prefix}")"

  if [[ $# -gt 0 ]]; then
    echo "Usage: ./dev.sh soilph" >&2
    echo "Télécharge le dernier pH H₂O EcoDataCube (0–20 cm) pour l'emprise et le recadre à 50 m." >&2
    exit 1
  fi

  echo "Résolution STAC EcoDataCube (pH) via Docker…"
  ensure_tools_image
  docker run --rm --user "${DOCKER_USER}" \
    -v "${ROOT}:/work" \
    -w /work \
    "${TOOLS_IMAGE}" \
    python3 scripts/fetch_soil_ph.py /work/backend/var/soilph/source
  if [[ ! -f "${source_dir}/manifest.json" ]]; then
    echo "Manifeste pH absent." >&2
    exit 1
  fi

  echo "Recadrage pH via Docker (GDAL)…"
  docker_rm_rf "${prefix}.bin"
  docker_rm_rf "${prefix}.json"
  docker_gdal \
    -v "${ROOT}/scripts:/scripts:ro" \
    -v "${ROOT}/backend/var/soilph:/soilph" \
    -- \
    python3 /scripts/convert_soil_ph.py /soilph/soil-ph --manifest /soilph/source/manifest.json
  echo "Relancez ./dev.sh precompute pour écrire la colonne soil_ph."
}

# Converts a LIDAR HD canopy-height model (CHM, metres) onto the 50 m grid.
lidar() {
  local prefix="${ROOT}/backend/var/lidar/canopy-height"
  local source_dir="${ROOT}/backend/var/lidar/source"
  local -a docker_args=(/lidar/canopy-height)

  require_docker
  mkdir -p "${source_dir}" "$(dirname "${prefix}")"

  ensure_tools_image
  docker run --rm --user "${DOCKER_USER}" \
    -v "${ROOT}:/work" \
    -w /work \
    "${TOOLS_IMAGE}" \
    python3 scripts/fetch_lidar_chm.py /work/backend/var/lidar/source

  if [[ $# -eq 0 ]]; then
    echo "Usage: ./dev.sh lidar <chm.tif> [autres…]   ou   ./dev.sh lidar --manifest …" >&2
    echo "Téléchargez MNS/MNT LIDAR HD sur https://geoservices.ign.fr/lidarhd (CHM = MNS−MNT)." >&2
    exit 1
  fi

  if [[ "${1:-}" == "--manifest" ]]; then
    docker_args+=(--manifest "/lidar/source/manifest.json")
  else
    local staging="${ROOT}/backend/var/lidar/_inputs"
    mkdir -p "${staging}"
    for source in "$@"; do
      cp -a "${source}" "${staging}/"
      docker_args+=("/lidar/_inputs/$(basename "${source}")")
    done
  fi

  echo "Recadrage CHM LIDAR via Docker (GDAL)…"
  docker_rm_rf "${prefix}.bin"
  docker_rm_rf "${prefix}.json"
  docker_gdal \
    -v "${ROOT}/scripts:/scripts:ro" \
    -v "${ROOT}/backend/var/lidar:/lidar" \
    -- \
    python3 /scripts/convert_lidar_chm.py "${docker_args[@]}"
  echo "Relancez ./dev.sh precompute pour écrire la colonne canopy_height."
}

# Downloads (Géoplateforme) and/or converts IGN RGE ALTI onto the 50 m grid.
# Preferred elevation source at precompute; falls back to Terrarium if absent.
rgealti() {
  local prefix="${ROOT}/backend/var/elevation/rge-alti"
  local source_dir="${ROOT}/backend/var/elevation/rge-source"
  local manifest="${source_dir}/manifest.json"
  local -a docker_args=(/elevation/rge-alti)
  local archive extract_rel extract_dir

  require_docker
  mkdir -p "${source_dir}/archives" "${source_dir}/extracted" "$(dirname "${prefix}")"

  if [[ $# -eq 0 ]]; then
    echo "Téléchargement RGE ALTI (API Géoplateforme, 5 m, dép. 26/38/73)…"
    ensure_tools_image
    docker run --rm --user "${DOCKER_USER}" \
      -e RGE_ALTI_ZONES="${RGE_ALTI_ZONES:-}" \
      -e RGE_ALTI_RESOLUTION="${RGE_ALTI_RESOLUTION:-}" \
      -v "${ROOT}:/work" \
      -w /work \
      "${TOOLS_IMAGE}" \
      python3 scripts/fetch_rge_alti.py /work/backend/var/elevation/rge-source
    if [[ ! -f "${manifest}" ]]; then
      echo "Manifeste RGE ALTI absent." >&2
      exit 1
    fi
    # Extract each archive into the path recorded in the manifest.
    while IFS=$'\t' read -r archive extract_rel; do
      [[ -n "${archive}" && -n "${extract_rel}" ]] || continue
      extract_dir="${source_dir}/${extract_rel}"
      if [[ -d "${extract_dir}" ]] && find "${extract_dir}" -name '*.asc' -print -quit 2>/dev/null | grep -q .; then
        echo "Déjà extrait : ${extract_rel}"
        continue
      fi
      echo "Extraction $(basename "${archive}") → ${extract_rel}…"
      extract_archive_docker "${archive}" "${extract_dir}"
    done < <(
      python3 - "${manifest}" "${source_dir}" <<'PY'
import json
import sys
from pathlib import Path

manifest = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
base = Path(sys.argv[2])
for product in manifest.get("products") or []:
    extract = product.get("extracted")
    if not extract:
        continue
    files = product.get("files") or []
    if not files:
        path = product.get("path")
        files = path if isinstance(path, list) else ([path] if path else [])
    for name in files:
        path = base / str(name)
        if path.suffix.lower() in {".7z", ".zip"} and path.is_file():
            print(f"{path}\t{extract}")
PY
    )
    docker_args+=(--manifest "/elevation/rge-source/manifest.json")
  elif [[ "${1:-}" == "--manifest" ]]; then
    if [[ ! -f "${manifest}" ]]; then
      echo "Manifeste absent : lancez d'abord ./dev.sh rgealti (sans argument)." >&2
      exit 1
    fi
    docker_args+=(--manifest "/elevation/rge-source/manifest.json")
  else
    local staging="${ROOT}/backend/var/elevation/_rge_inputs"
    mkdir -p "${staging}"
    for source in "$@"; do
      cp -a "${source}" "${staging}/"
      docker_args+=("/elevation/_rge_inputs/$(basename "${source}")")
    done
  fi

  echo "Recadrage RGE ALTI via Docker (GDAL)…"
  docker_rm_rf "${prefix}.bin"
  docker_rm_rf "${prefix}.json"
  docker_gdal \
    -v "${ROOT}/scripts:/scripts:ro" \
    -v "${ROOT}/backend/var/elevation:/elevation" \
    -- \
    python3 /scripts/convert_rge_alti.py "${docker_args[@]}"
  echo "Relancez ./dev.sh precompute : le relief préférera RGE ALTI à Terrarium."
}

case "${1:-}" in
  install) install_all ;;
  precompute) shift; precompute "$@" ;;
  restore-data) restore_data ;;
  export-data) export_data ;;
  bdforet) shift; bdforet "$@" ;;
  geologie) shift; geologie "$@" ;;
  tcd) shift; tcd "$@" ;;
  soilph) shift; soilph "$@" ;;
  lidar) shift; lidar "$@" ;;
  rgealti) shift; rgealti "$@" ;;
  backend) backend ;;
  frontend) frontend ;;
  docker) docker_up ;;
  *)
    echo "Usage: ./dev.sh [install|restore-data|precompute|export-data|bdforet|geologie|tcd|soilph|lidar|rgealti|backend|frontend|docker]"
    echo
    echo "  install       Installe les dépendances PHP et JS"
    echo "  restore-data  Restaure la base précalculée depuis data/"
    echo "  precompute    Recalcule la base depuis les sources distantes"
    echo "  export-data   Réexporte la base vers data/ pour publication"
    echo "  bdforet       Convertit BD Forêt V2 pour un couvert forestier précis"
    echo "  geologie      Convertit BRGM Charm-50 pour le substrat"
    echo "  tcd           Télécharge / convertit Copernicus Tree Cover Density (0–100 %)"
    echo "  soilph        Télécharge / convertit EcoDataCube pH du sol (30 m → 50 m)"
    echo "  lidar         Convertit un CHM LIDAR HD IGN (hauteur de canopée, m)"
    echo "  rgealti       Télécharge / convertit RGE ALTI (MNT IGN 5 m) pour le relief"
    echo "  backend       Démarre l'API sur http://127.0.0.1:8765"
    echo "  frontend      Démarre l'interface sur http://127.0.0.1:43123"
    echo "  docker        Démarre API + interface via Docker Compose"
    exit 1
    ;;
esac
