# Forage Mapper

Application web locale pour planifier vos sorties mycologiques dans les massifs de la région grenobloise (**Chartreuse**, **Belledonne**, **Vercors**).

Croise plusieurs sources de données publiques gratuites pour estimer la pertinence de chaque zone selon l'espèce visée :

- **Relief** : altitude, pente, exposition (via EU-DEM / OpenTopoData)
- **Forêt** : type de couvert forestier (OpenStreetMap / Overpass)
- **Météo** : pluies récentes, température, humidité (Open-Meteo)

## Stack

- **Backend** : Symfony 7 (API JSON)
- **Frontend** : Vue 3 + TypeScript + Leaflet

## Prérequis

- PHP 8.3+ avec extensions `curl`, `xml`, `mbstring`
- Composer
- Node.js 20+

## Installation

```bash
chmod +x dev.sh
./dev.sh install
```

## Lancement en local

Dans **deux terminaux** :

```bash
./dev.sh backend    # http://127.0.0.1:8765
./dev.sh frontend   # http://127.0.0.1:43123
```

Ouvrez [http://127.0.0.1:43123](http://127.0.0.1:43123).

## Utilisation

1. Choisissez le **massif**, l'**espèce**, la **maille** (250–750 m) et le mode d'affichage (quadrillage, cercles, heatmap).
2. Déplacez/zoomer la carte puis cliquez sur **Analyser la zone visible**.
3. Cliquez sur une maille pour voir le **score**, les **facteurs explicatifs** et la **fenêtre de cueillette**.

### Espèces couvertes

Cèpe, trompette de la mort, chanterelles (toutes espèces locales), girolle, pied de mouton, morille.

## Approche de scoring

Modèle **transparent par règles expertes** (pas de boîte noire ML) :

- Fenêtre de saison par espèce
- Conditions météo récentes (poids ~25 %)
- Relief et **exposition** en montagne (poids ~35 %)
- Type de forêt OSM (poids ~25 %)

Évolutif : les règles peuvent être affinées avec vos spots de référence, puis un modèle ML pourra s'appuyer sur ces labels.

## Sources de données (100 % gratuites pour le MVP)

| Source | Usage | Clé API |
|--------|-------|---------|
| [OpenTopoData EU-DEM 25m](https://www.opentopodata.org/) | Altitude, pente, exposition | Non |
| [OpenStreetMap / Overpass](https://overpass-api.de/) | Forêts | Non |
| [Open-Meteo](https://open-meteo.com/) | Météo & humidité sol | Non |
| [OpenStreetMap tiles](https://www.openstreetmap.org/) | Fond de carte | Non |

### APIs payantes (non requises pour l'instant)

- **IGN Géoportail** (BD Forêt, orthophotos) : plus précis pour les essences, gratuit avec inscription — utile en v2.
- **Mapbox / MapTiler** : tuiles premium — OSM suffit pour le MVP.

## Créer le dépôt GitHub `thomerdos/forage-mapper`

Ce projet est prêt à être poussé sur votre compte GitHub :

```bash
# Sur github.com : créer un repo privé "forage-mapper" (sans README)

git remote add github git@github.com:thomerdos/forage-mapper.git
git push -u github main
```

## Structure

```
backend/     API Symfony (/api/regions, /api/species, /api/map)
frontend/    Interface Vue + Leaflet
dev.sh       Scripts de démarrage local
```

## Limites connues (MVP)

- La classification forestière OSM est approximative (pas d'essences arbres fines).
- OpenTopoData limite à ~1 requête/s : la première analyse d'une zone peut prendre 30–90 s (résultats mis en cache).
- Le score météo est régional (centre du massif), pas maille par maille.

## Licence

Usage personnel — à définir si ouverture publique ultérieure.
