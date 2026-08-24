#!/usr/bin/env bash
# One-shot setup runnable from a normal WSL terminal (Docker group access).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

echo "==> Docker compose up (build + start)"
docker compose up --build -d

echo "==> Attente API…"
for i in $(seq 1 60); do
  if curl -fsS "http://127.0.0.1:8765/api/context" >/tmp/myco-context.json 2>/dev/null; then
    echo "API OK"
    head -c 400 /tmp/myco-context.json; echo
    break
  fi
  sleep 2
done

echo "==> Frontend : http://127.0.0.1:43123"
echo "==> API      : http://127.0.0.1:8765"
docker compose ps
