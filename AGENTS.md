# AGENTS.md

## Cursor Cloud specific instructions

Myco Map is a two-service app: a PHP/Symfony backend API and a Vue 3 + Vite frontend. The
`dev.sh` script at the repo root is the source of truth for every dev command; read it before
inventing your own. Standard prerequisites, commands and API routes are documented in
`README.md`.

### Services

- Backend (Symfony API): `./dev.sh backend` → serves on `http://127.0.0.1:8765` via the PHP
  built-in server. Routes: `/api/context`, `/api/layer`, `/api/location` (see `README.md`).
- Frontend (Vue/Vite dev server): `./dev.sh frontend` → serves on `http://127.0.0.1:43123`
  and proxies `/api` to the backend. Open this URL in the browser; run the backend too or the
  map has no data. Run each in its own long-lived terminal (e.g. tmux).

### Non-obvious caveats

- The precomputed SQLite database lives at `backend/var/data/myco.sqlite`, which is gitignored.
  It is produced from the committed archive `data/myco-terrain-100m.sqlite.gz` by
  `./dev.sh restore-data` (already run by the startup update script). Do NOT run
  `./dev.sh precompute` unless you specifically need to rebuild from remote sources — it
  downloads DEM tiles + OSM data and takes several minutes. `restore-data` is the fast,
  offline path and is idempotent (it drops and re-restores the DB).
- `backend/.env` is gitignored and auto-created from `backend/.env.example` by `dev.sh`
  (`ensure_env`). No manual secrets are required — all upstream data sources (AWS Terrain
  Tiles, Overpass/OSM, Open-Meteo) are keyless and free.
- Weather is fetched live from Open-Meteo at request time (2h cache), so `/api/location`
  needs outbound network access; terrain/cover data is served entirely from the local SQLite.

### Lint / test / build

- There is no PHPUnit suite. Backend "lint" == `php bin/console lint:container` and
  `php bin/console lint:yaml config` (run from `backend/`), matching `.github/workflows/ci.yml`.
- Frontend has no separate test script; `npm run build` (in `frontend/`) runs `vue-tsc`
  type-checking followed by the Vite build and is what CI uses to validate the frontend.
