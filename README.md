# Myco Map

Carte de potentiel mycologique pour la région grenobloise — **Chartreuse**, **Belledonne** et **Vercors** traitées comme une seule zone continue.

L'application croise relief, couvert forestier, hydrographie et météo récente pour estimer, maille par maille, l'intérêt d'un secteur pour une espèce donnée. Chaque score est explicable : la carte indique toujours *pourquoi* une zone ressort.

## Ce que fait l'application

- **Carte raster continue** à 100 m de résolution, lissée à l'affichage : pas de quadrillage visible, les zones épousent la forme réelle des forêts et du relief.
- **Masques de critères** interchangeables : potentiel de l'espèce, altitude, exposition, pente, couvert forestier, humidité topographique, distance à la lisière, pluie déclenchante.
- **Six espèces** avec profils d'habitat distincts et fenêtres de cueillette : cèpe, trompette de la mort, chanterelles, girolle, pied de mouton, morille.
- **Détail au clic** : altitude, pente, exposition, couvert, distance lisière et eau, plus la décomposition complète du score critère par critère.
- **Meilleurs secteurs visibles** classés, espacés d'au moins 900 m pour proposer des points de départ distincts.
- **Fonds de carte** plan, topographique et satellite.

## Approche du modèle

Modèle **par règles expertes**, volontairement transparent plutôt qu'une boîte noire :

| Critère | Poids | Ce qui est évalué |
|---|---|---|
| Météo récente | 22 % | Pluie déclenchante J-14 à J-5, pluie des 5 derniers jours, température, humidité |
| Couvert forestier | 18 % | Adéquation feuillus / conifères / mixte avec les hôtes de l'espèce |
| Saison | 16 % | Position dans les fenêtres de cueillette de l'espèce |
| Altitude | 15 % | Appartenance à la tranche altitudinale, en trapèze |
| Exposition | 15 % | Fraîcheur du versant, **cible ajustée selon l'altitude** |
| Humidité topographique | 8 % | Concavité du terrain, drainage, proximité de l'eau |
| Position lisière | 4 % | Lisière recherchée ou cœur de massif selon l'espèce |
| Pente | 2 % | Écart à la pente optimale |

Le poids fort donné à l'exposition et son **ajustement altitudinal** sont propres au contexte montagnard : en bas les versants nord gardent l'humidité, plus haut les versants plus chauds prennent l'avantage.

## Architecture

Découpage DDD, sans suffixe `Service` :

```
backend/src/
├── Domain/              # Métier pur, sans dépendance framework
│   ├── Geo/             # Coordinates, BoundingBox, Grid, GridWindow, SurveyArea
│   ├── Terrain/         # TerrainProfile, ForestCover, Exposure + ports
│   ├── Weather/         # WeatherConditions, WeatherField + port
│   ├── Mycology/        # Species, SuitabilityCalculator, Criterion, SeasonAssessment
│   └── Cartography/     # MapLayer, LayerLegendFactory, LayerValueResolver
├── Application/         # Cas d'usage
│   ├── Cartography/     # RenderLayerGrid, InspectLocation
│   └── Precomputation/  # PrecomputeTerrain
└── Infrastructure/      # Adaptateurs techniques
    ├── Elevation/       # TerrariumTileElevation (tuiles DEM AWS)
    ├── LandCover/       # OverpassLandCover (OpenStreetMap)
    ├── Weather/         # OpenMeteoWeather
    ├── Persistence/     # DbalTerrainCellStore (SQLite)
    ├── Raster/          # PolygonRasterizer, ChamferDistance, TerrainDerivatives
    ├── Mycology/        # InMemorySpeciesCatalog
    ├── Http/Controller/ # MapController
    └── Console/         # PrecomputeCommand
```

Le domaine définit des **ports** (`ElevationSampler`, `LandCoverSource`, `WeatherSource`, `TerrainCellStore`, `SpeciesCatalog`) implémentés dans l'infrastructure et câblés dans `config/services.yaml`.

## Précalcul

La partie statique du modèle est calculée une fois et stockée en SQLite :

1. Téléchargement des tuiles de relief (~13 m/pixel) et échantillonnage bilinéaire des 587 000 mailles
2. Pente, exposition et courbure par différences finies de Horn
3. Rasterisation des polygones forestiers OSM, clairières comprises
4. Rasterisation de l'hydrographie
5. Transformées de distance aux lisières et aux cours d'eau
6. Écriture en base

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
cp backend/.env.example backend/.env
chmod +x dev.sh
./dev.sh install
./dev.sh precompute     # une fois, puis à rafraîchir si besoin
```

Puis, dans deux terminaux :

```bash
./dev.sh backend        # http://127.0.0.1:8765
./dev.sh frontend       # http://127.0.0.1:43123
```

Ouvrez [http://127.0.0.1:43123](http://127.0.0.1:43123).

## API

| Route | Rôle |
|---|---|
| `GET /api/context` | Zone couverte, masques disponibles, catalogue d'espèces, état du précalcul |
| `GET /api/layer` | Grille de valeurs d'un masque pour une emprise (`south`, `west`, `north`, `east`, `layer`, `species`) |
| `GET /api/location` | Rapport détaillé pour un point (`lat`, `lng`, `species`) |

## Sources de données

Toutes gratuites et sans clé d'API :

| Source | Usage |
|---|---|
| [AWS Terrain Tiles](https://registry.opendata.aws/terrain-tiles/) | Relief : altitude, pente, exposition, courbure |
| [OpenStreetMap / Overpass](https://overpass-api.de/) | Couvert forestier, essences, hydrographie |
| [Open-Meteo](https://open-meteo.com/) | Pluie, température, humidité, humidité du sol |
| [OpenTopoMap](https://opentopomap.org/) · OSM · Esri | Fonds de carte |

### Aucune API payante n'est nécessaire

Deux pistes payantes ou sur inscription pourraient affiner le modèle plus tard, sans être requises :

- **IGN Géoservices — BD Forêt V2** : essences forestières bien plus précises qu'OSM. Gratuit sur inscription.
- **Copernicus / Sentinel-2** : indice de végétation et fermeture du couvert, utile pour détecter les coupes récentes.

## Limites connues

- Les essences OSM restent approximatives : beaucoup de polygones ne portent pas de tag `leaf_type`, classés alors « essence indéterminée ».
- Le modèle ne connaît ni la nature géologique du sol ni le pH, qui comptent notamment pour la trompette et la morille.
- Les fenêtres de cueillette sont calées sur des moyennes régionales, pas sur la phénologie de l'année en cours.
- La pression de cueillette et l'accessibilité réelle (propriété privée, réglementation locale) ne sont pas modélisées.

## Calibrage avec vos spots

Le modèle gagne surtout à être confronté au terrain :

1. Sélectionnez l'espèce, placez-vous sur un secteur que vous connaissez et cliquez dessus.
2. Comparez le score et la décomposition par critère à ce que vous observez réellement.
3. Les profils d'espèces sont regroupés dans `backend/src/Infrastructure/Mycology/InMemorySpeciesCatalog.php` : tranches d'altitude, préférence de fraîcheur, affinités de couvert, rapport à la lisière et à l'humidité s'y ajustent directement.
4. Les poids des critères sont dans `backend/src/Domain/Mycology/Criterion.php`.

## Licence

Projet personnel, usage privé.
