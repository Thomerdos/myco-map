#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
COMPOSER="${ROOT}/composer"

backend() {
  cd "${ROOT}/backend"
  if [[ ! -d vendor ]]; then
    php "${COMPOSER}" install --no-interaction
  fi
  echo "Backend API: http://127.0.0.1:8765"
  php -S 127.0.0.1:8765 -t public
}

frontend() {
  cd "${ROOT}/frontend"
  if [[ ! -d node_modules ]]; then
    npm install
  fi
  echo "Frontend: http://127.0.0.1:43123"
  npm run dev -- --host 0.0.0.0 --port 43123
}

install_all() {
  cd "${ROOT}/backend" && php "${COMPOSER}" install --no-interaction
  cd "${ROOT}/frontend" && npm install
}

case "${1:-}" in
  backend) backend ;;
  frontend) frontend ;;
  install) install_all ;;
  *)
    echo "Usage: ./dev.sh [backend|frontend|install]"
    exit 1
    ;;
esac
