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
  (cd "${ROOT}/backend" && php bin/console app:precompute "$@")
}

restore_data() {
  local archive="${ROOT}/data/myco-terrain-100m.sqlite.gz"
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
  local archive="${ROOT}/data/myco-terrain-100m.sqlite.gz"

  if [[ ! -f "${source}" ]]; then
    echo "Aucune base à exporter. Lancez ./dev.sh precompute d'abord." >&2
    exit 1
  fi

  mkdir -p "$(dirname "${archive}")"
  gzip -c "${source}" > "${archive}"
  echo "Archive écrite : ${archive} ($(du -h "${archive}" | cut -f1))"
  command -v sha256sum >/dev/null 2>&1 && sha256sum "${archive}"
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

case "${1:-}" in
  install) install_all ;;
  precompute) shift; precompute "$@" ;;
  restore-data) restore_data ;;
  export-data) export_data ;;
  backend) backend ;;
  frontend) frontend ;;
  *)
    echo "Usage: ./dev.sh [install|restore-data|precompute|export-data|backend|frontend]"
    echo
    echo "  install       Installe les dépendances PHP et JS"
    echo "  restore-data  Restaure la base précalculée depuis data/"
    echo "  precompute    Recalcule la base depuis les sources distantes"
    echo "  export-data   Réexporte la base vers data/ pour publication"
    echo "  backend       Démarre l'API sur http://127.0.0.1:8765"
    echo "  frontend      Démarre l'interface sur http://127.0.0.1:43123"
    exit 1
    ;;
esac
