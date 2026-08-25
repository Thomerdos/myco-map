# Myco Map

Carte de potentiel mycologique. Le jeu livré couvre la région grenobloise — **Chartreuse**,
**Belledonne** et **Vercors** en une seule zone. Le même pipeline peut servir une autre
région en changeant l'emprise et en y branchant ses couches (voir
[Adapter à une autre région](#adapter-à-une-autre-région)).

L'application croise relief, couvert forestier, hydrographie et météo récente pour estimer,
maille par maille, l'intérêt d'un secteur pour une espèce donnée. Chaque score est
explicable : la carte indique toujours *pourquoi* une zone ressort.

## Ce que fait l'application

- **Carte raster continue** à **50 m** : relief dérivé (pente, exposition, courbure),
  distances lisière / eau / accès ; masques continus lissés à l'affichage, couvert
  catégoriel (carrés nets).
- **Masques interchangeables** : chance de trouver, altitude, exposition, pente, couvert,
  densité du peuplement, humidité topographique, lisière, apport en eau, accès parking + chemin.
- **Six espèces** : cèpe, trompette de la mort, chanterelles, girolle, pied de mouton, morille.
- **Détail au clic** : terrain, décomposition du score, tracé d'accès et GPX d'itinéraire.
- **Secteurs ≥ 90** listés dans la vue, espacés pour proposer des départs distincts.
- **Export GPS** : KMZ (masque + points) et GPX (points libellés par l'indice).
- **Fonds** plan, topographique et satellite.

## Installation

Parcours nominal pour lancer la carte grenobloise telle qu'elle est livrée.

### 1. Prérequis

- [Docker](https://docs.docker.com/get-docker/) avec Compose v2

PHP, Composer et Node restent dans les images. Python 3 (et éventuellement GDAL) ne
servent que pour les scripts optionnels `geologie` et `tcd`.

### 2. Cloner et démarrer

```bash
git clone https://github.com/thomerdos/myco-map.git
cd myco-map
docker compose up --build
```

Au premier démarrage le backend :

1. copie `backend/.env.example` vers `backend/.env` s'il n'existe pas encore — valeurs
   locales prêtes à l'emploi (`APP_ENV=dev`, SQLite sous `var/data/myco.sqlite`, CORS
   limité à localhost / 127.0.0.1). **Rien à remplir** pour un usage local ; ne changez
   `APP_SECRET` que si vous exposez l'API hors de la machine. Les identifiants Copernicus
   (`CDSE_*`) ne vont pas dans ce fichier : ce sont des variables d'environnement shell
   pour `./dev.sh tcd` ;
2. installe les dépendances Composer (`vendor/`) si besoin ;
3. décompresse `data/myco-terrain-50m.sqlite.gz` vers `backend/var/data/myco.sqlite`
   s'il n'y a pas encore de base locale.

Le code est monté en volume : une modification PHP ou Vue est prise en compte sans rebuild.

### 3. Ouvrir l'interface

| Service | URL |
|---|---|
| Carte | [http://127.0.0.1:43123](http://127.0.0.1:43123) |
| API | [http://127.0.0.1:8765](http://127.0.0.1:8765) |

À ce stade l'application fonctionne déjà : vous pouvez ouvrir la carte, choisir une
espèce, zoomer et cliquer. La base livrée s'appuie surtout sur OpenStreetMap pour le
bois. Les étapes ci-dessous **ne sont pas obligatoires** pour tester, mais elles
améliorent nettement le score (essences, calcaire / acide, densité réelle du peuplement).

### 4. Couches optionnelles (recommandé)

**De quoi s'agit-il ?** Au démarrage, Myco Map a déjà le relief et la météo. Trois jeux
de données publics, téléchargeables à la main, affinent le reste :

| Couche | À quoi ça sert | Sans elle |
|---|---|---|
| **BD Forêt** (IGN) | Savoir *quelle* forêt (hêtre, sapin, mixte…) | Essences souvent « indéterminées » (OSM) |
| **Charm-50** (BRGM) | Calcaire vs siliceux (morille / trompette vs girolle) | Substrat indéterminé partout |
| **TCD** (Copernicus) | Densité du peuplement en % de couvert | Estimation grossière ouverte / fermée |

Vous pouvez en installer **une seule**, deux, ou les trois. L'ordre n'importe pas. En
revanche, après chaque ajout (ou une fois les trois faits), il faut **relancer le
précalcul** (étape 5) : sinon la carte continue d'utiliser l'ancienne base.

Les fichiers téléchargés restent sur votre disque dans `backend/var/` (souvent des
centaines de Mo). Ils ne sont **pas** envoyés sur Git. Les commandes ci-dessous
supposent que vous êtes à la racine du dépôt (`myco-map/`) dans un terminal, Docker
déjà capable de tourner.

Pour la zone grenobloise, on prend trois départements : **Isère (038)**, **Drôme (026)**
et **Savoie (073)**. Si vous adaptez une autre région française, remplacez ces numéros
par les codes départements qui recouvrent *votre* carte.

#### a. Couvert forestier précis — BD Forêt V2 (France)

Objectif : remplacer le couvert flou d'OpenStreetMap par la carte officielle des
formations végétales de l'IGN (Licence Ouverte).

1. Les trois archives ci-dessous correspondent à Isère, Drôme et Savoie (version 2,
   projection Lambert-93). Vous pouvez aussi les télécharger à la souris depuis
   [geoservices.ign.fr/bdforet](https://geoservices.ign.fr/bdforet) ; pour un autre
   département, cherchez le fichier dans
   `https://data.geopf.fr/telechargement/resource/BDFORET?zone=D0xx` (remplacez `0xx`).
2. Dans le terminal, depuis la racine du projet :

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

3. La commande produit `backend/var/bdforet/formation-vegetale.geojsonl`. Tant que ce
   fichier n'existe pas, Myco Map reste sur OpenStreetMap. Les rivières et sentiers
   viennent toujours d'OSM, même avec BD Forêt.

#### b. Géologie / substrat — Charm-50 (France)

Objectif : indiquer si le sol est plutôt calcaire, siliceux ou mixte (utile pour
discriminer morille / trompette et girolle). Données du BRGM.

1. Créez le dossier, téléchargez un ZIP par département, puis convertissez :

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

2. Vous pouvez aussi choisir le département sur le
   [formulaire Infoterre](https://infoterre.brgm.fr/formulaire/telechargement-cartes-geologiques-departementales-150-000-bd-charm-50)
   si le lien direct échoue.
3. Résultat attendu : `backend/var/geologie/formations.geojsonl`. Sans ce fichier, le
   critère géologie ne discrimine rien.

#### c. Densité du peuplement — Copernicus Tree Cover Density (Europe)

Objectif : une valeur continue 0–100 % de couvert arboré (meilleure que « forêt ouverte /
fermée »). Couvre toute l'Europe ; il faut un **compte gratuit**.

1. Créez un compte sur [dataspace.copernicus.eu](https://dataspace.copernicus.eu)
   (inscription, confirmation e-mail).
2. Dans le **même** terminal où vous lancez la commande, exportez vos identifiants
   (remplacez par les vôtres ; ils ne vont **pas** dans `backend/.env`) :

```bash
export CDSE_USERNAME='votre.email@exemple.fr'
export CDSE_PASSWORD='votre-mot-de-passe'
# alternative : export CDSE_REFRESH_TOKEN='…'
```

3. Téléchargez et convertissez les tuiles qui couvrent l'emprise configurée :

```bash
./dev.sh tcd
```

Par défaut le script prend la dernière année disponible. Pour figer une année :
`TCD_YEAR=2023 ./dev.sh tcd`. Si vous avez déjà des fichiers `.tif`, passez-les en
arguments : `./dev.sh tcd chemin/vers/tuile.tif …`.

4. Résultat attendu : `backend/var/tcd/canopy-cover.bin` et `.json`. Sans eux, Myco Map
   estime la densité à partir de BD Forêt / OSM (ouvert / fermé seulement).

Les bornes géographiques du téléchargement sont dans
[`scripts/fetch_tcd.py`](scripts/fetch_tcd.py). Si vous changez la zone de la carte
(section suivante), mettez-les à jour pour qu'elles correspondent.

### 5. Relancer le précalcul

Quand au moins une des conversions ci-dessus a réussi, **reconstruisez la base** pour que
la carte lise les nouvelles couches. Ce recalcul est **manuel** : rien ne se met à jour
tout seul ensuite (voir [Données : précalcul vs à la volée](#données--précalcul-vs-à-la-volée)).

```bash
./dev.sh precompute
```

(équivalent si Docker tourne déjà :
`docker compose exec backend php bin/console app:precompute`)

La première fois peut prendre plusieurs minutes (téléchargement du relief, requêtes
OpenStreetMap). Les fichiers intermédiaires restent en cache dans `backend/var/` :
les relances suivantes sont plus rapides. Rechargez ensuite la page dans le navigateur.

Vous n'avez en général **pas** besoin de `./dev.sh export-data` : cette commande ne sert
qu'à republier l'archive `data/myco-terrain-50m.sqlite.gz` pour le dépôt Git.
## Adapter à une autre région

Le clone restaure par défaut la base **grenobloise**. Pour une autre emprise :

1. **Bornes** dans [`backend/config/services.yaml`](backend/config/services.yaml) :
   `app.area.name`, `south`, `west`, `north`, `east`, `center_lat`, `center_lng`, `zoom`.
   Recopier les mêmes `south` / `west` / `north` / `east` dans
   [`scripts/fetch_tcd.py`](scripts/fetch_tcd.py).
2. **Oublier Grenoble** : supprimer `backend/var/data/myco.sqlite` (et `-wal` / `-shm`).
   Ne pas lancer `./dev.sh restore-data`.
3. **Couches locales** : mêmes commandes que ci-dessus, avec les départements (France) ou
   tuiles TCD (Europe) de *votre* rectangle. Hors France : OSM + DEM + météo suffisent ;
   BD Forêt et Charm-50 n'existent pas.
4. **Précalcul** : `./dev.sh precompute`. Grenoble ≈ 2,3 millions de mailles à 50 m ;
   une emprise plus large prend plus longtemps.
5. **Profils d'espèces** : les tranches d'altitude de
   [`InMemorySpeciesCatalog.php`](backend/src/Infrastructure/Mycology/InMemorySpeciesCatalog.php)
   sont calées sur les massifs grenoblois — à recalculer, puis confronter aux spots locaux.

Une emprise trop vaste saturera mémoire et Overpass. Restez sur un massif / un bassin.

## Utiliser la carte

### Export GPS

Le bouton **Exporter pour GPS** (panneau Affichage) enregistre un **instantané** de la vue
(espèce, date, emprise). Les taches ≥ 90 sont des points libellés par l'indice, classés
du meilleur au moins bon. Réexportez le jour J. Cadrer d'abord la zone du jour.

| Fichier | Contenu | Où l'ouvrir |
|---|---|---|
| **KMZ (zones)** | Masque coloré + points (libellé = indice) | Locus Map, AlpineQuest, Iphigénie |
| **GPX (points)** | Mêmes points, libellé = indice | OsmAnd, Visorando, Organic Maps |

Le fond IGN et la pastille GPS viennent de l'appli tierce. Le GPX proposé au clic
(Lieu / Marche) reste un chemin d'accès, pas les zones.

### Calibrage avec vos spots

1. Sélectionnez l'espèce, cliquez un secteur que vous connaissez.
2. Comparez le score et la décomposition à ce que vous observez.
3. Profils d'espèces : `backend/src/Infrastructure/Mycology/InMemorySpeciesCatalog.php`
   (altitude, fraîcheur, couvert, essence, densité, lisière, humidité).
4. Poids des critères : `backend/src/Domain/Mycology/Criterion.php`. Sources et divergences
   avec la littérature : [`AGENTS.md`](AGENTS.md).

## Comment ça marche

### Modèle de score

Modèle **par règles expertes**, volontairement transparent :

| Critère | Poids | Ce qui est évalué |
|---|---|---|
| Météo récente | 16 % | Phénologie de pousse, apport en eau, température, humidité |
| Densité du peuplement | 16 % | Taux de couvert Copernicus 0–100 % (repli FO/FF) |
| Couvert forestier | 14 % | Essence dominante / feuillu–conifère–mixte |
| Altitude | 13 % | Tranche altitudinale |
| Exposition | 13 % | Fraîcheur du versant (ajustée à l'altitude) |
| Géologie / substrat | 10 % | Calcaire / siliceux / mixte (BRGM Charm-50) |
| Humidité topographique | 9 % | Concavité, drainage, proximité de l'eau |
| Position lisière | 7 % | Lisière vs cœur de massif |
| Pente | 2 % | Effet négatif monotone |

La **saison** n'est pas un poids : hors fenêtre le score est plafonné à 38 (« À éviter »),
avec badge En saison / Hors saison. L'ajustement altitudinal de l'exposition est propre
au contexte montagnard : en bas les versants nord gardent l'humidité, plus haut les
versants plus chauds prennent l'avantage.

### Données : précalcul vs à la volée

Deux rythmes distincts :

| Donnée | Quand c'est chargé | Où ça vit |
|---|---|---|
| Relief (altitude, pente, exposition, courbure) | Au **précalcul** | SQLite `backend/var/data/myco.sqlite` |
| Couvert forestier, essences, densités FO/FF | Au **précalcul** (BD Forêt ou OSM) | Idem |
| Distances lisière / eau, réseau d'accès | Au **précalcul** (OSM) | Idem |
| Géologie / substrat | Au **précalcul** (si Charm-50 converti) | Idem |
| Densité continue 0–100 % | Au **précalcul** (si TCD converti) | Idem |
| **Météo** (pluie, température, humidité du sol) | **À chaque requête** carte / clic | Cache ~2 h, pas dans la base |
| Fonds de carte (plan, topo, satellite) | Navigateur, tuiles distantes | Jamais stockés par Myco Map |

Le précalcul écrit une grille fixe (~2,3 M mailles à 50 m sur Grenoble). Étapes internes :

1. Tuiles de relief (~13 m/pixel), échantillonnage bilinéaire
2. Pente, exposition, courbure (Horn)
3. Polygones forestiers (BD Forêt si présente, sinon OSM) → `StandCode`
4. Hydrographie
5. Distances lisière et cours d'eau
6. Accès : marche depuis parking / route OSM à fond blanc, 2 km + 500 m d'approche
7. Taux de couvert Copernicus TCD si présent, sinon FO/FF
8. Écriture en base

**Pas de mise à jour automatique.** Aucun cron, webhook ou tâche planifiée ne recharge
OpenStreetMap, le relief, BD Forêt, Charm-50 ou TCD. Tant que vous ne relancez pas
`./dev.sh precompute` (et, le cas échéant, ne re-téléchargez / reconvertissez pas les
couches optionnelles), la base reste celle du dernier calcul — y compris l'archive
fournie dans `data/` au premier démarrage. Seule la météo se rafraîchit d'elle-même
(sous réserve du cache de deux heures). Contenu détaillé de l'archive :
[`data/README.md`](data/README.md).

### Sources

| Source | Zone | Usage | Mode |
|---|---|---|---|
| [AWS Terrain Tiles](https://registry.opendata.aws/terrain-tiles/) | Monde | Relief | Précalcul (téléchargé alors) |
| [OpenStreetMap / Overpass](https://overpass-api.de/) | Monde | Couvert (repli), hydrographie, accès | Précalcul |
| [Open-Meteo](https://open-meteo.com/) | Monde | Pluie, température, humidité du sol | À la volée |
| [IGN BD Forêt® V2](https://geoservices.ign.fr/bdforet) | France | Essence et densité | Manuel puis précalcul |
| [BRGM Charm-50](https://infoterre.brgm.fr/formulaire/telechargement-cartes-geologiques-departementales-150-000-bd-charm-50) | France | Substrat | Manuel puis précalcul |
| [Copernicus Tree Cover Density](https://land.copernicus.eu/en/products/high-resolution-layer-forests-and-tree-cover/tree-cover-density-2018-present-raster-10-m-europe-yearly) | Europe | Couvert 0–100 % | Manuel puis précalcul |
| OpenTopoMap · OSM · Esri | Monde | Fonds de carte | Navigateur |

Licences et attributions : [`ATTRIBUTION.md`](ATTRIBUTION.md).

### Architecture logicielle

Découpage DDD, sans suffixe `Service` :

```
backend/src/
├── Domain/              # Métier pur, sans dépendance framework
│   ├── Geo/             # Coordinates, BoundingBox, Grid, GridWindow, SurveyArea
│   ├── Terrain/         # TerrainProfile, ForestCover, HostTree, StandCode, …
│   ├── Weather/         # WeatherConditions, WeatherField + port
│   ├── Mycology/        # Species, SuitabilityCalculator, Criterion, SeasonAssessment
│   └── Cartography/     # MapLayer, LayerLegendFactory, LayerValueResolver
├── Application/         # Cas d'usage (RenderLayerGrid, InspectLocation, PrecomputeTerrain)
└── Infrastructure/      # Adaptateurs (DEM, OSM / BD Forêt, Open-Meteo, SQLite, HTTP, console)
```

Ports du domaine (`ElevationSampler`, `LandCoverSource`, `GeologySource`,
`CanopyCoverSource`, `WeatherSource`, `TerrainCellStore`, `SpeciesCatalog`) câblés dans
`backend/config/services.yaml`.

### Commandes

| Commande | Rôle |
|---|---|
| `docker compose up --build` | API (8765) + interface (43123), PHP 8.4 |
| `./dev.sh docker` | Équivalent |
| `./dev.sh precompute` | Recalcule la base (via le conteneur) |
| `docker compose exec backend php bin/console app:precompute` | Idem, pile déjà lancée |
| `./dev.sh restore-data` | Restaure `data/` → `backend/var/data/` (déjà fait au premier `up`) |
| `./dev.sh export-data` | Réexporte la base vers `data/` |
| `./dev.sh bdforet <archives…>` | Convertit BD Forêt V2 |
| `./dev.sh geologie [zip…]` | Convertit BRGM Charm-50 |
| `./dev.sh tcd [tif…]` | Télécharge / convertit Copernicus TCD |

### API

| Route | Rôle |
|---|---|
| `GET /api/context` | Zone, masques, espèces, état du précalcul |
| `GET /api/layer` | Grille d'un masque (`south`, `west`, `north`, `east`, `layer`, `species`) |
| `GET /api/location` | Rapport détaillé (`lat`, `lng`, `species`) |

## Limites connues

- Les essences OSM restent approximatives sans BD Forêt (beaucoup de polygones sans
  `leaf_type` → « essence indéterminée »).
- La densité est un **taux de couvert Copernicus** (0–100 %), pas des m²/ha. Sans TCD :
  repli FO/FF.
- Charm-50 donne un **substrat**, pas un pH mesuré.
- Les fenêtres de cueillette sont des moyennes régionales, pas la phénologie de l'année.
- Pression de cueillette, propriété privée et réglementation locale ne sont pas
  modélisées. L'accès parking–chemin est un filtre d'affichage, pas un droit d'entrer.

## Licence et attribution

Le **code** est sous licence [MIT](LICENSE).

La **base précalculée** de `data/` dérive d'OpenStreetMap et reste sous
[ODbL 1.0](https://opendatacommons.org/licenses/odbl/1-0/) : toute redistribution doit
créditer les contributeurs d'OpenStreetMap et conserver cette licence. Détail :
[`ATTRIBUTION.md`](ATTRIBUTION.md).

## Avertissement

Les scores sont des estimations d'habitat favorable, pas des garanties de présence, et
ne disent rien de la comestibilité. Ne consommez jamais un champignon sans identification
certaine. Respectez la réglementation de cueillette et la propriété privée : la carte ne
modélise ni les droits d'accès ni les zones protégées.
