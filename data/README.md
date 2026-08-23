# Données précalculées

`myco-terrain-100m.sqlite.gz` contient la couche statique du modèle pour toute la zone d'étude, afin de pouvoir lancer l'application sans refaire les téléchargements.

## Contenu

| Champ | Description |
|---|---|
| `terrain_cell` | 587 076 mailles de 100 m |
| `latitude`, `longitude` | Centre de la maille |
| `elevation` | Altitude en mètres |
| `slope`, `aspect` | Pente et exposition en degrés (Horn) |
| `curvature` | Laplacien, positif en terrain concave |
| `cover` | Couvert forestier : 0 hors forêt, 1 indéterminé, 2 feuillus, 3 conifères, 4 mixte |
| | Cette archive est calculée sur OpenStreetMap. Un précalcul avec BD Forêt V2 (voir le README principal) remplit le même champ avec des essences bien plus fiables. |
| `edge_distance` | Distance signée à la lisière, positive dans le boisement |
| `water_distance` | Distance au cours d'eau le plus proche |
| `grid_definition` | Emprise, taille de maille, dimensions, date de calcul |

Emprise : 44,72–45,45 N et 5,38–6,30 E. Grille de 723 × 812 mailles de 100 m.

La météo n'est **pas** stockée : elle est récupérée et interpolée à chaque requête, avec un cache de deux heures.

## Restauration

```bash
./dev.sh restore-data
```

Ou manuellement :

```bash
mkdir -p backend/var/data
gunzip -c data/myco-terrain-100m.sqlite.gz > backend/var/data/myco.sqlite
```

Empreinte de l'archive :

```
e947550e5658999b68da22b442887261791909eac9b89290d0c8a78c2ef09668
```

La base décompressée pèse 38 072 320 octets.

## Régénération

```bash
cd backend && php bin/console app:precompute
```

Puis pour republier l'archive :

```bash
gzip -c backend/var/data/myco.sqlite > data/myco-terrain-100m.sqlite.gz
sha256sum data/myco-terrain-100m.sqlite.gz
```

Comptez quelques minutes : 528 tuiles de relief et une vingtaine de requêtes Overpass, mises en cache dans `backend/var/`.

## Licence

Cette base est une **base de données dérivée d'OpenStreetMap**, donc placée sous [ODbL 1.0](https://opendatacommons.org/licenses/odbl/1-0/), comme détaillé dans [`../ATTRIBUTION.md`](../ATTRIBUTION.md). Toute redistribution doit conserver cette licence et créditer les contributeurs d'OpenStreetMap.
