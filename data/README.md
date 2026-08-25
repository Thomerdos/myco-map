# Données précalculées

`myco-terrain-50m.sqlite.gz` contient la couche statique du modèle pour toute la zone d'étude, afin de pouvoir lancer l'application sans refaire les téléchargements.

## Contenu

| Champ | Description |
|---|---|
| `terrain_cell` | 2 349 750 mailles de 50 m |
| `latitude`, `longitude` | Centre de la maille |
| `elevation` | Altitude en mètres |
| `slope`, `aspect` | Pente et exposition en degrés (Horn) |
| `curvature` | Laplacien, positif en terrain concave |
| `cover` | Entier empaqueté (`StandCode`) : 3 bits de couvert (0 hors forêt, 1 indéterminé, 2 feuillus, 3 conifères, 4 mixte), puis 4 bits d'essence dominante (`HostTree`) et 2 bits de densité (`CanopyClosure`). Les archives historiques qui n'ont écrit que 0–4 restent valides : hôte et densité s'y lisent comme inconnus. |
| | Un précalcul avec BD Forêt V2 (voir le README principal) remplit les bits d'essence et de densité, beaucoup plus fiables que le seul repli OSM. |
| `edge_distance` | Distance signée à la lisière, positive dans le boisement |
| `water_distance` | Distance au cours d'eau le plus proche |
| `access_distance` | Marche depuis un parking OSM ou une route à fond blanc (`tertiary` / `unclassified` / `residential`), le long des chemins (budget 2 km), plus 500 m d'approche. Les pistes `track` ne sont pas un départ voiture. 9999 = hors d'atteinte |
| `park`, `path` | Masques 0/1 du réseau d'accès (route/parking vs sentier), utilisés pour reconstruire le tracé au clic |
| `canopy_cover` | Taux de couvert arboré 0–100 (Copernicus TCD). NULL si le raster n'a pas été ingéré (`./dev.sh tcd`) : le score retombe alors sur les bits FO/FF de `cover`. |
| `grid_definition` | Emprise, taille de maille, dimensions, date de calcul |

Emprise : 44,72–45,45 N et 5,38–6,30 E. Grille de **1 446 × 1 625** mailles de **50 m**.

La météo n'est **pas** stockée : elle est récupérée et interpolée à chaque requête, avec un cache de deux heures.

## Migration depuis 100 m

La configuration (`app.area.cell_size`) est passée à 50 m. L'archive `myco-terrain-100m.sqlite.gz` **n'est plus compatible** : il faut régénérer la base.

```bash
./dev.sh precompute      # + BD Forêt si disponible (./dev.sh bdforet)
                         # + TCD Copernicus si disponible (./dev.sh tcd)
./dev.sh export-data     # publie data/myco-terrain-50m.sqlite.gz
```

Sans ce re-précalcul, l'API répondra que les données ne sont pas prêtes (ou restera sur une ancienne base 100 m incohérente avec la config).

## Restauration

```bash
./dev.sh restore-data
```

Ou manuellement :

```bash
mkdir -p backend/var/data
gunzip -c data/myco-terrain-50m.sqlite.gz > backend/var/data/myco.sqlite
```

Empreinte de l'archive :

```
c90387d141acd715c189981616acdb2077a1a3b9e891cc2d38f210a468173e26
```

La base décompressée pèse environ 150 Mo.
## Régénération

```bash
cd backend && php bin/console app:precompute
```

Puis pour republier l'archive :

```bash
./dev.sh export-data
# ou :
gzip -c backend/var/data/myco.sqlite > data/myco-terrain-50m.sqlite.gz
sha256sum data/myco-terrain-50m.sqlite.gz
```

Comptez davantage de temps qu'à 100 m (~4× de mailles) : tuiles de relief et requêtes Overpass / BD Forêt, mises en cache dans `backend/var/`.

## Licence

Cette base est une **base de données dérivée d'OpenStreetMap** (et éventuellement de BD Forêt sous Licence Ouverte et de Copernicus TCD), donc placée sous [ODbL 1.0](https://opendatacommons.org/licenses/odbl/1-0/) pour la part OSM, comme détaillé dans [`../ATTRIBUTION.md`](../ATTRIBUTION.md). Toute redistribution doit conserver cette licence et créditer les contributeurs d'OpenStreetMap.
