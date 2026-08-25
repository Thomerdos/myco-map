# Myco Map

Carte de potentiel mycologique. Le jeu livré couvre la région grenobloise — **Chartreuse**,
**Belledonne** et **Vercors** en une seule zone. Le pipeline (précalcul + sources optionnelles)
est fait pour qu'une autre région puisse être branchée en changeant l'emprise et en y
ajoutant ses propres couches (voir [Adapter à une autre région](#adapter-à-une-autre-région)).

L'application croise relief, couvert forestier, hydrographie et météo récente pour estimer, maille par maille, l'intérêt d'un secteur pour une espèce donnée. Chaque score est explicable : la carte indique toujours *pourquoi* une zone ressort.

## Ce que fait l'application

- **Carte raster continue** à **50 m** de résolution : le couvert, le relief dérivé (pente, exposition, courbure) et les distances lisière / eau sont plus fins qu'à 100 m ; les masques continus sont lissés à l'affichage, le couvert reste catégoriel (carrés nets).
- **Masques de critères** interchangeables : chance de trouver (score combiné), altitude, exposition, pente, couvert forestier, densité du peuplement, humidité topographique, distance à la lisière, apport en eau, accès parking + chemin.
- **Six espèces** avec profils d'habitat distincts et fenêtres de cueillette : cèpe, trompette de la mort, chanterelles, girolle, pied de mouton, morille.
- **Détail au clic** : altitude, pente, exposition, couvert, essence dominante, densité, distances lisière, cours d'eau et accès, plus la décomposition du score (explication par critère).
- **Meilleurs secteurs visibles** classés, espacés d'au moins 900 m pour proposer des points de départ distincts.
- **Export GPS** : KMZ (masque + points libellés par l'indice) et GPX (mêmes points) pour Locus, OsmAnd, Visorando…
- **Fonds de carte** plan, topographique et satellite.

## Approche du modèle

Modèle **par règles expertes**, volontairement transparent plutôt qu'une boîte noire :

| Critère | Poids | Ce qui est évalué |
|---|---|---|
| Météo récente | 16 % | Phénologie de pousse, apport en eau (épisode + accumulation), température, humidité |
| Densité du peuplement | 16 % | Taux de couvert Copernicus 0–100 % (repli FO/FF) |
| Couvert forestier | 14 % | Essence dominante / feuillu–conifère–mixte |
| Altitude | 13 % | Tranche altitudinale |
| Exposition | 13 % | Fraîcheur du versant (ajustée à l'altitude) |
| Géologie / substrat | 10 % | Calcaire / siliceux / mixte (BRGM Charm-50) |
| Humidité topographique | 9 % | Concavité, drainage, proximité de l'eau |
| Position lisière | 7 % | Lisière vs cœur de massif |
| Pente | 2 % | Effet négatif monotone |

La **saison** n'est pas un poids : hors fenêtre de cueillette le score est plafonné à 38
(« À éviter »), et l'interface affiche En saison / Hors saison. Ça empêche d'imaginer des
morilles en septembre tout en laissant les 100 points classer le terrain en pleine saison.

Le poids fort donné à l'exposition et son **ajustement altitudinal** sont propres au contexte montagnard : en bas les versants nord gardent l'humidité, plus haut les versants plus chauds prennent l'avantage.

## Architecture

Découpage DDD, sans suffixe `Service` :

```
backend/src/
├── Domain/              # Métier pur, sans dépendance framework
│   ├── Geo/             # Coordinates, BoundingBox, Grid, GridWindow, SurveyArea
│   ├── Terrain/         # TerrainProfile, ForestCover, HostTree, CanopyClosure, StandCode, Exposure, AccessThreshold, CanopyCoverSource + ports
│   ├── Weather/         # WeatherConditions, WeatherField + port
│   ├── Mycology/        # Species, SuitabilityCalculator, Criterion, SeasonAssessment
│   └── Cartography/     # MapLayer, LayerLegendFactory, LayerValueResolver
├── Application/         # Cas d'usage
│   ├── Cartography/     # RenderLayerGrid, InspectLocation
│   └── Precomputation/  # PrecomputeTerrain
└── Infrastructure/      # Adaptateurs techniques
    ├── Elevation/       # TerrariumTileElevation (tuiles DEM AWS)
    ├── LandCover/       # BdForetLandCover (optionnel) + OverpassLandCover (OpenStreetMap)
    ├── Weather/         # OpenMeteoWeather
    ├── Persistence/     # DbalTerrainCellStore (SQLite)
    ├── Raster/          # PolygonRasterizer, ChamferDistance, TerrainDerivatives, PathNetworkAccess, TreeCoverDensityRaster
    ├── Mycology/        # InMemorySpeciesCatalog
    ├── Http/Controller/ # MapController
    └── Console/         # PrecomputeCommand
```

Le domaine définit des **ports** (`ElevationSampler`, `LandCoverSource`, `GeologySource`, `CanopyCoverSource`, `WeatherSource`, `TerrainCellStore`, `SpeciesCatalog`) implémentés dans l'infrastructure et câblés dans `config/services.yaml`.

## Précalcul

La partie statique du modèle est calculée une fois et stockée en SQLite :

1. Téléchargement des tuiles de relief (~13 m/pixel) et échantillonnage bilinéaire des 587 000 mailles
2. Pente, exposition et courbure par différences finies de Horn
3. Rasterisation des polygones forestiers (BD Forêt si présente, sinon OSM), clairières comprises, essence et densité empaquetées dans un `StandCode`
4. Rasterisation de l'hydrographie
5. Transformées de distance aux lisières et aux cours d'eau
6. Accès : marche depuis un parking ou une route OSM à fond blanc (pas les pistes), budget 2 km + 500 m d'approche
7. Taux de couvert Copernicus TCD (si `./dev.sh tcd` a produit le raster), sinon repli FO/FF
8. Écriture en base

La météo reste dynamique : elle est récupérée et interpolée à la requête, avec un cache de deux heures.

```bash
docker compose exec backend php bin/console app:precompute
```

Comptez plusieurs minutes la première fois (téléchargements). Les tuiles DEM et les réponses Overpass sont mises en cache dans `backend/var/`, les relances suivantes sont donc rapides et hors ligne.

## Prérequis

- [Docker](https://docs.docker.com/get-docker/) avec Compose v2

PHP, Composer et Node restent dans les images : rien à installer sur l'hôte, hors Docker
(et Python 3 / GDAL seulement pour les scripts optionnels `geologie` et `tcd`).

## Installation et lancement

```bash
git clone https://github.com/thomerdos/myco-map.git
cd myco-map
docker compose up --build
```

Ouvrez [http://127.0.0.1:43123](http://127.0.0.1:43123). L'API est sur
[http://127.0.0.1:8765](http://127.0.0.1:8765).

Au premier démarrage, le backend installe les dépendances PHP et décompresse
`data/myco-terrain-50m.sqlite.gz` s'il n'y a pas encore de base locale. Le code est monté
en volume : une modification PHP ou Vue est prise en compte sans rebuild.

**Après le passage à 50 m**, une ancienne archive 100 m n'est plus utilisable : lancez
`docker compose exec backend php bin/console app:precompute` (idéalement avec BD Forêt via
`./dev.sh bdforet`), puis `./dev.sh export-data` pour publier la nouvelle archive.

## Emporter la carte sur le téléphone

Le bouton **Exporter pour GPS** (panneau Affichage) enregistre un **instantané** de la vue
(espèce, date, emprise). Les taches ≥ 90 sont des points libellés par l'indice, classés
du meilleur au moins bon. Réexportez le jour J. Cadrer d'abord la zone du jour.

| Fichier | Contenu | Où l'ouvrir |
|---|---|---|
| **KMZ (zones)** | Masque coloré + points (libellé = indice) | Locus Map, AlpineQuest, Iphigénie |
| **GPX (points)** | Mêmes points, libellé = indice | OsmAnd, Visorando, Organic Maps |

Le fond IGN et la pastille GPS viennent de l'appli. Le GPX proposé au clic (Lieu / Marche)
reste un chemin d'accès, pas les zones.

## Commandes disponibles

| Commande | Rôle |
|---|---|
| `docker compose up --build` | API (8765) + interface (43123), PHP 8.4 |
| `./dev.sh precompute` | Recalcule la base (via le conteneur backend) |
| `docker compose exec backend php bin/console app:precompute` | Idem, pile déjà lancée |
| `./dev.sh restore-data` | Restaure la base précalculée depuis `data/` (déjà fait au premier `up`) |
| `./dev.sh export-data` | Réexporte la base vers `data/` pour publication |
| `./dev.sh bdforet <shp…>` | Convertit BD Forêt V2 pour un couvert forestier précis |
| `./dev.sh geologie [zip…]` | Convertit BRGM Charm-50 pour le substrat |
| `./dev.sh tcd [tif…]` | Télécharge / convertit Copernicus Tree Cover Density (0–100 %) |
| `./dev.sh docker` | Équivalent de `docker compose up --build` |

## API

| Route | Rôle |
|---|---|
| `GET /api/context` | Zone couverte, masques disponibles, catalogue d'espèces, état du précalcul |
| `GET /api/layer` | Grille de valeurs d'un masque pour une emprise (`south`, `west`, `north`, `east`, `layer`, `species`) |
| `GET /api/location` | Rapport détaillé pour un point (`lat`, `lng`, `species`) |

## Sources de données

Toutes gratuites. Relief, OSM et météo sont **récupérés tout seuls** au précalcul. BD Forêt,
Charm-50 et TCD Copernicus sont **optionnels** : sans eux l'app tourne (couvert OSM, densité
FO/FF, substrat indéterminé). Les fichiers bruts vont dans `backend/var/` et **ne se
committent pas**.

| Source | Zone | Usage | Quand |
|---|---|---|---|
| [AWS Terrain Tiles](https://registry.opendata.aws/terrain-tiles/) | Monde | Altitude, pente, exposition, courbure | Automatique |
| [OpenStreetMap / Overpass](https://overpass-api.de/) | Monde | Couvert, hydrographie, accès (repli si pas de BD Forêt) | Automatique |
| [Open-Meteo](https://open-meteo.com/) | Monde | Pluie, température, humidité du sol | À la requête |
| [IGN BD Forêt® V2](https://geoservices.ign.fr/bdforet) | France | Essence et densité de peuplement | Manuel, ci-dessous |
| [BRGM Charm-50](https://infoterre.brgm.fr/formulaire/telechargement-cartes-geologiques-departementales-150-000-bd-charm-50) | France | Substrat calcaire / siliceux / mixte | Manuel, ci-dessous |
| [Copernicus Tree Cover Density](https://land.copernicus.eu/en/products/high-resolution-layer-forests-and-tree-cover/tree-cover-density-2018-present-raster-10-m-europe-yearly) | Europe | Taux de couvert 0–100 % | Manuel, ci-dessous |
| OpenTopoMap · OSM · Esri | Monde | Fonds de carte (navigateur) | Automatique |

Pour Grenoble, les départements à couvrir sont **Isère (038)**, plus **Drôme (026)** et
**Savoie (073)** pour les franges du Vercors et de Belledonne. Remplacez `038` / `026` /
`073` par les codes INSEE de votre emprise.

### 1. Couvert — BD Forêt V2 (France)

Page produit : [geoservices.ign.fr/bdforet](https://geoservices.ign.fr/bdforet)
(Licence Ouverte 2.0). Prendre **version 2**, shapefile **Lambert-93**, un fichier par
département. Téléchargement direct Géoplateforme (le millésime `2014-04-01` est celui de
ces trois départements ; pour un autre, lister
`https://data.geopf.fr/telechargement/resource/BDFORET?zone=D0xx`) :

```bash
mkdir -p backend/var/bdforet
cd backend/var/bdforet

curl -fLO "https://data.geopf.fr/telechargement/download/BDFORET/BDFORET_2-0__SHP_LAMB93_D038_2014-04-01/BDFORET_2-0__SHP_LAMB93_D038_2014-04-01.7z"
curl -fLO "https://data.geopf.fr/telechargement/download/BDFORET/BDFORET_2-0__SHP_LAMB93_D026_2014-04-01/BDFORET_2-0__SHP_LAMB93_D026_2014-04-01.7z"
curl -fLO "https://data.geopf.fr/telechargement/download/BDFORET/BDFORET_2-0__SHP_LAMB93_D073_2014-04-01/BDFORET_2-0__SHP_LAMB93_D073_2014-04-01.7z"

cd ../../..
./dev.sh bdforet \
  backend/var/bdforet/BDFORET_2-0__SHP_LAMB93_D038_2014-04-01.7z \
  backend/var/bdforet/BDFORET_2-0__SHP_LAMB93_D026_2014-04-01.7z \
  backend/var/bdforet/BDFORET_2-0__SHP_LAMB93_D073_2014-04-01.7z
```

Écrit `backend/var/bdforet/formation-vegetale.geojsonl`. Absent → repli OSM. Hydrographie
toujours OSM. Autre chemin : `BDFORET_PATH`.

### 2. Géologie — Charm-50 (France)

Formulaire : [Infoterre / Charm-50](https://infoterre.brgm.fr/formulaire/telechargement-cartes-geologiques-departementales-150-000-bd-charm-50).
Archives directes (un ZIP par département, couche `*_S_FGEOL_2154`) :

```bash
mkdir -p backend/var/geologie/source
curl -fL -o backend/var/geologie/source/GEO050K_HARM_038.zip \
  "https://infoterre.brgm.fr/telechargements/BDCharm50/GEO050K_HARM_038.zip"
curl -fL -o backend/var/geologie/source/GEO050K_HARM_026.zip \
  "https://infoterre.brgm.fr/telechargements/BDCharm50/GEO050K_HARM_026.zip"
curl -fL -o backend/var/geologie/source/GEO050K_HARM_073.zip \
  "https://infoterre.brgm.fr/telechargements/BDCharm50/GEO050K_HARM_073.zip"

./dev.sh geologie
```

Écrit `backend/var/geologie/formations.geojsonl`. Absent → substrat indéterminé partout.
Autre chemin : `GEOLOGIE_PATH`.

### 3. Densité — Copernicus Tree Cover Density (Europe)

Compte gratuit : [dataspace.copernicus.eu](https://dataspace.copernicus.eu) (inscription).
L'emprise interrogée est celle de [`scripts/fetch_tcd.py`](scripts/fetch_tcd.py) (bornes
`SOUTH` / `WEST` / `NORTH` / `EAST`, à tenir alignées avec `app.area.*` dans
[`backend/config/services.yaml`](backend/config/services.yaml)).

```bash
export CDSE_USERNAME='…'
export CDSE_PASSWORD='…'   # ou CDSE_REFRESH_TOKEN
./dev.sh tcd               # dernière année ; TCD_YEAR=2023 pour figer
```

GeoTIFF déjà téléchargés : `./dev.sh tcd tuile.tif [tuile2.tif…]`.
Écrit `backend/var/tcd/canopy-cover.bin` + `.json`. Absent → repli FO/FF. Autre chemin :
`TCD_PATH`.

### Intégrer les couches dans la base

Après une ou plusieurs conversions ci-dessus :

```bash
./dev.sh precompute
```

(équivalent : `docker compose exec backend php bin/console app:precompute`)

## Adapter à une autre région

Le clone par défaut **restaure la base grenobloise** (`data/myco-terrain-50m.sqlite.gz`).
Pour une autre emprise, ne pas s'en servir : changer les bornes, télécharger les couches
de *cette* emprise, puis précalculer.

1. **Emprise** dans [`backend/config/services.yaml`](backend/config/services.yaml) :
   `app.area.name`, `south`, `west`, `north`, `east`, `center_lat`, `center_lng`, `zoom`.
   Recopier les mêmes `south` / `west` / `north` / `east` dans
   [`scripts/fetch_tcd.py`](scripts/fetch_tcd.py).
2. **Oublier Grenoble** : supprimer `backend/var/data/myco.sqlite` (et `-wal` / `-shm`).
   Ne pas lancer `./dev.sh restore-data`.
3. **Couches locales** : mêmes commandes que ci-dessus, avec les départements (France) ou
   tuiles TCD (Europe) qui recouvrent *votre* rectangle. Hors France : OSM + DEM + météo
   suffisent ; BD Forêt et Charm-50 n'existent pas.
4. **Précalcul** : `./dev.sh precompute`. Comptez plus longtemps si l'emprise est plus
   grande (Grenoble ≈ 2,3 millions de mailles à 50 m).
5. **Profils d'espèces** : les tranches d'altitude de
   [`InMemorySpeciesCatalog.php`](backend/src/Infrastructure/Mycology/InMemorySpeciesCatalog.php)
   sont calées sur les massifs grenoblois. Recalez-les, puis confrontez aux spots locaux
   (section suivante).

Une emprise trop vaste saturera mémoire et Overpass. Restez sur un massif / un bassin,
pas un pays entier.

## Limites connues

- Les essences OSM restent approximatives : beaucoup de polygones ne portent pas de tag `leaf_type`, classés alors « essence indéterminée ». BD Forêt V2 lève cette limite (voir ci-dessus).
- La densité de peuplement est un **taux de couvert Copernicus** (0–100 %), pas des m²/ha. Sans raster TCD, repli FO/FF.
- La géologie Charm-50 donne un **substrat** (calcaire / siliceux / mixte), pas un pH de sol mesuré.
- Les fenêtres de cueillette sont calées sur des moyennes régionales, pas sur la phénologie de l'année en cours.
- La pression de cueillette, la propriété privée et la réglementation locale ne sont pas modélisées. L'accès parking–chemin (2 km le long d'OSM) est un filtre d'affichage, pas un droit d'entrer.

## Calibrage avec vos spots

Le modèle gagne surtout à être confronté au terrain :

1. Sélectionnez l'espèce, placez-vous sur un secteur que vous connaissez et cliquez dessus.
2. Comparez le score et la décomposition par critère à ce que vous observez réellement.
3. Les profils d'espèces sont regroupés dans `backend/src/Infrastructure/Mycology/InMemorySpeciesCatalog.php` : tranches d'altitude, préférence de fraîcheur, affinités de couvert, d'essence et de densité, rapport à la lisière et à l'humidité s'y ajustent directement.
4. Les poids des critères sont dans `backend/src/Domain/Mycology/Criterion.php`. Leur justification, les sources scientifiques qui les appuient et les divergences connues avec la littérature sont documentées dans [`AGENTS.md`](AGENTS.md).

## Licence et attribution

Le **code** est sous licence [MIT](LICENSE).

La **base précalculée** de `data/` dérive d'OpenStreetMap et reste donc sous [ODbL 1.0](https://opendatacommons.org/licenses/odbl/1-0/) : toute redistribution doit créditer les contributeurs d'OpenStreetMap et conserver cette licence. Les sources, leurs licences et les attributions à reproduire sont détaillées dans [`ATTRIBUTION.md`](ATTRIBUTION.md).

## Avertissement

Les scores sont des estimations d'habitat favorable, pas des garanties de présence, et ne disent rien de la comestibilité. Ne consommez jamais un champignon sans identification certaine. Respectez la réglementation de cueillette et la propriété privée : la carte ne modélise ni les droits d'accès ni les zones protégées.
