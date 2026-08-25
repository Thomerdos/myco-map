# Sources de données et licences

Le **code** de ce dépôt est sous licence MIT (voir [`LICENSE`](LICENSE)). Les **données** obéissent aux licences de leurs sources respectives, qui sont plus contraignantes.

## OpenStreetMap — couvert forestier et hydrographie

© les contributeurs d'OpenStreetMap, sous [Open Database License (ODbL) 1.0](https://opendatacommons.org/licenses/odbl/1-0/).

Le fichier `data/myco-terrain-50m.sqlite.gz` intègre des données OSM rasterisées : type de couvert forestier, distance aux lisières, distance aux cours d'eau. Il constitue donc une **base de données dérivée** au sens de l'ODbL. Trois conséquences pratiques :

- **Attribution** : toute réutilisation doit créditer « © les contributeurs d'OpenStreetMap ».
- **Partage à l'identique** : si vous redistribuez cette base ou une base qui en dérive, vous devez la publier sous ODbL.
- **Produits dérivés** : les images de carte produites à partir de la base peuvent être diffusées sous la licence de votre choix, à condition de mentionner la source et de préciser où obtenir la base sous ODbL.

## IGN BD Forêt® V2 — couvert forestier (optionnel)

Utilisée seulement si vous la téléchargez vous-même puis lancez `./dev.sh bdforet` : elle
**n'est pas redistribuée** par ce dépôt, et la base précalculée de `data/` est construite sur
OpenStreetMap.

BD Forêt® V2 est diffusée par l'[IGN](https://geoservices.ign.fr/bdforet) sous
[Licence Ouverte 2.0](https://www.etalab.gouv.fr/licence-ouverte-open-licence/), qui demande la
mention de la source :

> Source : IGN — BD Forêt® version 2.0

Si vous republiez une base qui mélange BD Forêt et des données OSM (lisières, hydrographie),
l'ODbL de la section précédente continue de s'appliquer à l'ensemble.

## Copernicus HRL — Tree Cover Density (optionnel)

Utilisé seulement si vous lancez `./dev.sh tcd` : les GeoTIFF **ne sont pas redistribués** par ce
dépôt. Produit [High Resolution Layer Tree Cover Density](https://land.copernicus.eu/en/products/high-resolution-layer-forests-and-tree-cover/tree-cover-density-2018-present-raster-10-m-europe-yearly)
du Copernicus Land Monitoring Service (dérivé Sentinel-2, 10 m, 0–100 %), téléchargé via
l'[API OData](https://documentation.dataspace.copernicus.eu/APIs/OData.html) du Copernicus Data
Space. Accès libre avec compte [dataspace.copernicus.eu](https://dataspace.copernicus.eu).
Licence Copernicus (utilisation libre sous réserve d'attribution) :

> Contains modified Copernicus Land Monitoring Service data [année]

La colonne `canopy_cover` de la base dérive de ce produit lorsqu'il a été ingéré.

## AWS Terrain Tiles — relief

Jeu de données [Terrain Tiles](https://registry.opendata.aws/terrain-tiles/) du programme AWS Open Data, héritier du projet Mapzen. Il agrège plusieurs sources selon la zone ; pour les Alpes françaises il s'appuie principalement sur le SRTM de la NASA et sur des levés nationaux.

Attribution recommandée par le fournisseur :

> Terrain Tiles — données issues de sources multiples, voir <https://github.com/tilezen/joerd/blob/master/docs/attribution.md>

Les valeurs d'altitude, de pente, d'exposition et de courbure de la base dérivent de ce jeu de données.

## Open-Meteo — météo

API [Open-Meteo](https://open-meteo.com/), sous [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). Les données météo ne sont pas stockées dans le dépôt : elles sont interrogées à la demande et mises en cache deux heures.

## Fonds de carte

Affichés dans le navigateur, jamais redistribués par ce dépôt :

| Fond | Attribution | Conditions |
|---|---|---|
| Plan | © les contributeurs d'OpenStreetMap | [Politique d'usage des tuiles OSM](https://operations.osmfoundation.org/policies/tiles/) |
| Topo | © [OpenTopoMap](https://opentopomap.org/) (CC BY-SA), données OSM | Usage raisonnable |
| Satellite | Imagery © [Esri](https://www.esri.com/) | [Conditions Esri](https://www.esri.com/en-us/legal/terms/full-master-agreement) |

Ces trois services sont destinés à un usage personnel et modéré. Pour un déploiement public, prévoyez un fournisseur de tuiles avec un contrat adapté.

## Avertissement

Les scores produits sont des **estimations statistiques d'habitat favorable**, pas des garanties de présence, et encore moins un avis sur la comestibilité. Ne consommez jamais un champignon sans identification certaine, au besoin auprès d'une société mycologique ou d'un pharmacien. Respectez la réglementation locale de cueillette, les quotas, les arrêtés préfectoraux et la propriété privée : la carte ne modélise ni les droits d'accès ni les zones protégées.
