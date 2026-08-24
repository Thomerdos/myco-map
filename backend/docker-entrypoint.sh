#!/bin/sh
set -eu

cd /app

if [ ! -f .env ]; then
  cp .env.example .env
  echo "backend/.env créé depuis .env.example"
fi

if [ ! -d vendor ]; then
  composer install --no-interaction
fi

if [ ! -f var/data/myco.sqlite ]; then
  if [ -f /data/myco-terrain-50m.sqlite.gz ]; then
    mkdir -p var/data
    gunzip -c /data/myco-terrain-50m.sqlite.gz > var/data/myco.sqlite
    echo "Base restaurée : var/data/myco.sqlite"
  else
    echo "Attention : aucune base précalculée (data/myco-terrain-50m.sqlite.gz manquant)." >&2
  fi
fi

exec "$@"
