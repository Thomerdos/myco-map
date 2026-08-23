# Myco Map

Carte de potentiel mycologique pour la région grenobloise — **Chartreuse**, **Belledonne** et **Vercors** traitées comme une seule zone continue.

L'application croise relief, couvert forestier, hydrographie et météo récente pour estimer, maille par maille, l'intérêt d'un secteur pour une espèce donnée. Chaque score est explicable : la carte indique toujours *pourquoi* une zone ressort.

## Ce que fait l'application

- **Carte raster continue** à 100 m de résolution, lissée à l'affichage : pas de quadrillage visible, les zones épousent la forme réelle des forêts et du relief.
- **Masques de critères** interchangeables : chance de trouver (score combiné), altitude, exposition, pente, couvert forestier, humidité topographique, distance à la lisière, pluie déclenchante.
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

`restore-data` décompresse l'archive de `data/` et évite complètement l'étape de précalcul. Pour recalculer depuis les sources distantes, utilisez `./dev.sh precompute`, puis `./dev.sh export-data` pour régénérer l'archive publiée.

## Commandes disponibles

| Commande | Rôle |
|---|---|
| `./dev.sh install` | Dépendances PHP et JavaScript |
| `./dev.sh restore-data` | Restaure la base précalculée depuis `data/` |
| `./dev.sh precompute` | Recalcule la base depuis les sources distantes |
| `./dev.sh export-data` | Réexporte la base vers `data/` pour publication |
| `./dev.sh bdforet <shp…>` | Convertit BD Forêt V2 pour un couvert forestier précis |
| `./dev.sh backend` | API sur le port 8765 |
| `./dev.sh frontend` | Interface sur le port 43123 |

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

**Copernicus / Sentinel-2** reste une piste non implémentée : indice de végétation et fermeture du
couvert, utiles pour la densité du peuplement et les coupes récentes.

## Limites connues

- Les essences OSM restent approximatives : beaucoup de polygones ne portent pas de tag `leaf_type`, classés alors « essence indéterminée ». BD Forêt V2 lève cette limite (voir ci-dessus).
- La densité et la fermeture du couvert ne sont pas modélisées, alors que la surface terrière est la variable de peuplement la plus corrélée aux récoltes.
- Le modèle ne connaît ni la nature géologique du sol ni le pH, qui comptent notamment pour la trompette et la morille.
- Les fenêtres de cueillette sont calées sur des moyennes régionales, pas sur la phénologie de l'année en cours.
- La pression de cueillette et l'accessibilité réelle (propriété privée, réglementation locale) ne sont pas modélisées.

## Calibrage avec vos spots

Le modèle gagne surtout à être confronté au terrain :

1. Sélectionnez l'espèce, placez-vous sur un secteur que vous connaissez et cliquez dessus.
2. Comparez le score et la décomposition par critère à ce que vous observez réellement.
3. Les profils d'espèces sont regroupés dans `backend/src/Infrastructure/Mycology/InMemorySpeciesCatalog.php` : tranches d'altitude, préférence de fraîcheur, affinités de couvert, rapport à la lisière et à l'humidité s'y ajustent directement.
4. Les poids des critères sont dans `backend/src/Domain/Mycology/Criterion.php`. Leur justification, les sources scientifiques qui les appuient et les divergences connues avec la littérature sont documentées dans [`AGENTS.md`](AGENTS.md).

## Licence et attribution

Le **code** est sous licence [MIT](LICENSE).

La **base précalculée** de `data/` dérive d'OpenStreetMap et reste donc sous [ODbL 1.0](https://opendatacommons.org/licenses/odbl/1-0/) : toute redistribution doit créditer les contributeurs d'OpenStreetMap et conserver cette licence. Les sources, leurs licences et les attributions à reproduire sont détaillées dans [`ATTRIBUTION.md`](ATTRIBUTION.md).

## Avertissement

Les scores sont des estimations d'habitat favorable, pas des garanties de présence, et ne disent rien de la comestibilité. Ne consommez jamais un champignon sans identification certaine. Respectez la réglementation de cueillette et la propriété privée : la carte ne modélise ni les droits d'accès ni les zones protégées.
