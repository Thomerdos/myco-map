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
  backend) backend ;;
  frontend) frontend ;;
  *)
    echo "Usage: ./dev.sh [install|precompute|backend|frontend]"
    exit 1
    ;;
esac
