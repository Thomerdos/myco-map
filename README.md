# Myco Map

Carte de potentiel mycologique pour la région grenobloise — **Chartreuse**, **Belledonne** et **Vercors** traitées comme une seule zone continue.

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
cd backend
php bin/console app:precompute
```

Comptez plusieurs minutes la première fois (téléchargements). Les tuiles DEM et les réponses Overpass sont mises en cache dans `backend/var/`, les relances suivantes sont donc rapides et hors ligne.

## Prérequis

- PHP 8.3+ avec `gd`, `sqlite3`, `curl`, `mbstring`, `xml`
- Composer
- Node.js 20+

Sur Debian/Ubuntu ou WSL :

```bash
sudo apt install -y php-cli php-gd php-sqlite3 php-curl php-mbstring php-xml unzip
```

## Installation et lancement

```bash
git clone https://github.com/thomerdos/myco-map.git
cd myco-map
chmod +x dev.sh
./dev.sh install
./dev.sh restore-data   # base précalculée fournie, pas de téléchargement
```

Puis, dans deux terminaux :

```bash
./dev.sh backend        # http://127.0.0.1:8765
./dev.sh frontend       # http://127.0.0.1:43123
```

Ouvrez [http://127.0.0.1:43123](http://127.0.0.1:43123).

`restore-data` décompresse `data/myco-terrain-50m.sqlite.gz` et évite le précalcul. **Après le passage à 50 m**, une ancienne archive 100 m n'est plus utilisable : lancez `./dev.sh precompute` (idéalement avec BD Forêt via `./dev.sh bdforet`), puis `./dev.sh export-data` pour publier la nouvelle archive.

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
| `./dev.sh install` | Dépendances PHP et JavaScript |
| `./dev.sh restore-data` | Restaure la base précalculée depuis `data/` |
| `./dev.sh precompute` | Recalcule la base depuis les sources distantes |
| `./dev.sh export-data` | Réexporte la base vers `data/` pour publication |
| `./dev.sh bdforet <shp…>` | Convertit BD Forêt V2 pour un couvert forestier précis |
| `./dev.sh geologie [zip…]` | Convertit BRGM Charm-50 pour le substrat |
| `./dev.sh tcd [tif…]` | Télécharge / convertit Copernicus Tree Cover Density (0–100 %) |
| `./dev.sh backend` | API sur le port 8765 |
| `./dev.sh frontend` | Interface sur le port 43123 |

## API

| Route | Rôle |
|---|---|
| `GET /api/context` | Zone couverte, masques disponibles, catalogue d'espèces, état du précalcul |
| `GET /api/layer` | Grille de valeurs d'un masque pour une emprise (`south`, `west`, `north`, `east`, `layer`, `species`) |
| `GET /api/location` | Rapport détaillé pour un point (`lat`, `lng`, `species`) |

## Sources de données

Toutes gratuites. L'application n'utilise pas de clé d'API ; seul le téléchargement TCD
demande un compte [Copernicus Data Space](https://dataspace.copernicus.eu).

| Source | Usage |
|---|---|
| [AWS Terrain Tiles](https://registry.opendata.aws/terrain-tiles/) | Relief : altitude, pente, exposition, courbure |
| [OpenStreetMap / Overpass](https://overpass-api.de/) | Couvert forestier, essences, hydrographie |
| [Open-Meteo](https://open-meteo.com/) | Pluie, température, humidité, humidité du sol |
| [Copernicus HRL Tree Cover Density](https://land.copernicus.eu/en/products/high-resolution-layer-forests-and-tree-cover/tree-cover-density-2018-present-raster-10-m-europe-yearly) | Taux de couvert 0–100 % (optionnel, `./dev.sh tcd`) |
| [OpenTopoMap](https://opentopomap.org/) · OSM · Esri | Fonds de carte |

### Couvert forestier précis avec BD Forêt V2 (optionnel)

Par défaut le couvert vient d'OpenStreetMap, où beaucoup de boisements ne portent aucun tag
d'essence et finissent en « essence indéterminée » — une classe qui note pareil pour toutes les
espèces. **BD Forêt® V2** de l'IGN décrit la formation végétale de chaque plage de 0,5 ha et plus,
ce qui rend le critère de couvert (18 % du score) réellement discriminant.

1. Téléchargez les départements voulus sur [geoservices.ign.fr/bdforet](https://geoservices.ign.fr/bdforet)
   — gratuit, Licence Ouverte. Pour cette zone : **Isère**, plus **Drôme** et **Savoie** pour les
   franges du Vercors et de Belledonne.
2. Convertissez-les (nécessite `gdal-bin` pour `ogr2ogr`) :

```bash
./dev.sh bdforet /chemin/vers/FORMATION_VEGETALE.shp
./dev.sh precompute
```

La conversion écrit `backend/var/bdforet/formation-vegetale.geojsonl` (GeoJSON une ligne par
polygone, reprojeté en WGS84). Dès que ce fichier existe, il est utilisé automatiquement ; sinon
l'application retombe sur OpenStreetMap sans rien casser. L'hydrographie vient toujours d'OSM.
Un autre emplacement se déclare avec la variable d'environnement `BDFORET_PATH`.

### Densité du peuplement — Copernicus Tree Cover Density

Le critère densité lit le **taux de couvert arboré 0–100 %** (produit Sentinel-2 Copernicus HRL,
pas le NDVI brut, qui sature dans les peuplements fermés). Courbe en cloche par espèce : l'optimum
de rendement est un couvert intermédiaire, pas le plus fermé.

Les tuiles 10 m de l'emprise sont téléchargées via l'[API OData](https://documentation.dataspace.copernicus.eu/APIs/OData.html)
du Copernicus Data Space (collection `CLMS`, jeu `clms_vlcc_tree-cover-density_europe_10m_yearly_v1`).
Un compte gratuit sur [dataspace.copernicus.eu](https://dataspace.copernicus.eu) est requis pour le
téléchargement (pas pour l'application elle-même). Nécessite `gdal-bin` pour la conversion.

```bash
export CDSE_USERNAME='…'
export CDSE_PASSWORD='…'   # ou CDSE_REFRESH_TOKEN
./dev.sh tcd               # dernière année disponible ; TCD_YEAR=2023 pour figer
./dev.sh precompute
```

Des GeoTIFF déjà téléchargés se convertissent hors-ligne : `./dev.sh tcd tuile.tif [tuile2.tif…]`.

La conversion écrit `backend/var/tcd/canopy-cover.bin` + `.json` (grille 50 m). Dès que ces
fichiers existent, ils sont utilisés automatiquement ; sinon le modèle retombe sur FO/FF
sans rien casser. Un autre emplacement se déclare avec `TCD_PATH`. Les tuiles brutes restent dans
`backend/var/tcd/source/` (jamais commitées).

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
