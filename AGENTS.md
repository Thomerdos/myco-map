# AGENTS.md

Notes de travail pour les agents (et les humains) qui interviennent sur ce dépôt.

## Conventions du dépôt

- **Langue** : tout ce qui est visible par l'utilisateur (libellés, explications, messages de commit, README) est en **français**. Les commentaires de code sont en **anglais**.
- **Backend** : Symfony 7 / PHP 8.4, domaine sans dépendance à l'infrastructure (`backend/src/Domain`), adaptateurs dans `backend/src/Infrastructure`.
- **Frontend** : Vue 3 + Vite + Leaflet, TypeScript strict. Police unique : Inter.
- **Installation** : Docker Compose uniquement (PHP 8.4, Node, et les scripts
  `bdforet` / `geologie` / `tcd` / `precompute` via images Docker). Rien à installer
  sur l'hôte hors Docker.
- **Emprise** : `app.area.*` dans `backend/config/services.yaml` (Grenoble par défaut). Toute
  autre région : mêmes clés + recopier les bornes dans `scripts/fetch_tcd.py`, **ne pas**
  restaurer l'archive grenobloise, télécharger BD Forêt / Charm-50 / TCD de *cette* emprise,
  `./dev.sh precompute`. Détail dans le README (« Adapter à une autre région »).
- **Vérification avant commit** : `docker compose exec frontend npm run build` (inclut `vue-tsc`). Le backend n'a pas de suite de tests.
- **Serveurs de dev** : `docker compose up` — API port 8765, interface port 43123.
- **AGENTS.md** : à tenir à jour après chaque changement de modèle, de source, de grille, de
  libellé métier ou de sémantique UI (poids, délais, accès, ce que l'interface montre).

## Modèle de score : pondérations et sources

Le score est une somme pondérée de **neuf** critères (`backend/src/Domain/Mycology/Criterion.php`),
suivie de plafonds (`SuitabilityCalculator::applyCaps`). La **saison** et l'accès parking–chemin
ne sont **pas** des poids : ce sont des garde-fous / filtres (voir ci-dessous).

### Re-challenge obligatoire des poids

**Toute modification du modèle (nouveau critère, nouvelle source, nouvelle espèce, changement
de grille) doit re-challenger les pondérations** avec des données d'intérêt — pas seulement
recaler la somme à 1,0.

Sources d'intérêt, par ordre de préférence :

1. **Spots / absences locales** (calibrage du README) : sur une emprise connue, comparer
   `statistics.average` / `statistics.best` et le détail au clic avant / après.
2. **Littérature de rendement** : sens et classement des effets (pas les pourcentages exacts).
3. **Cohérence spatiale** : un poids élevé sur une variable quasi uniforme sur l'emprise
   (météo à date fixe) ne discrimine pas les secteurs — il faut le justifier explicitement.

Les poids restent des **priors d'expert** tant qu'aucun jeu de récoltes géolocalisées n'alimente
le dépôt. Un prior non confronté à (1) ou (2) après un changement de modèle est considéré
comme **non validé**.

### Statut honnête des poids

**Ces poids sont des priors d'expert, pas des coefficients ajustés sur des données d'observation.**
Aucun jeu de données de récolte géolocalisée n'alimente ce dépôt. La littérature citée ci-dessous
valide le **classement** et le **sens** des effets, pas les pourcentages exacts. Les meilleurs
modèles publiés de rendement à partir de la seule météo plafonnent autour de R² ≈ 0,22
([Martínez-Peña et al. 2012](https://doi.org/10.1016/j.foreco.2012.06.034)) : viser une précision
meilleure que ~5 points sur un poids n'a pas de sens.

### Audit des pondérations actuelles (août 2026)

| Critère | Poids | Appui dans la littérature | Verdict |
|---|---|---|---|
| Météo / rythme de pousse | 16 % | Pluie et température dans tous les modèles de rendement | Compromis spatial ; uniforme sur une emprise à date fixe |
| **Densité du peuplement** | **16 %** | Surface terrière = prédicteur stand le plus fort ; optimum ~15–20 m²/ha ([Bonet et al. 2010](https://doi.org/10.1139/x09-198)) | TCD 0–100 % (courbe en cloche) ; repli FO/FF. Reçoit une part de l'ex-poids saison pour classer les mailles |
| Couvert forestier | 14 % | Hôte ectomycorhizien = premier contributeur MaxEnt | Bien appuyé ; densité sortie en critère séparé |
| Altitude | 13 % | Facteur de site significatif ([Bonet et al. 2010](https://hal.science/hal-00884160v1/document)) | Bien appuyé |
| Exposition | 13 % | Versants nord > sud ([Bonet et al. 2010](https://hal.science/hal-00884160v1/document)) | Bien appuyé |
| **Géologie / substrat** | **10 %** | Trompette / morille calcaire ; girolle plutôt acide (savoir + fiches d'espèce) | Discriminant local Chartreuse–Belledonne ; pas un pH mesuré |
| Humidité topographique | 9 % | Humidité du sol télédétectée (r = 0,63–0,72, [Hernández-Rodríguez et al. 2020](https://doi.org/10.1016/j.agrformet.2020.108020)) | Défendable |
| Position lisière | 7 % | −65 % de richesse en lisière ([Rianhard et al. 2025](https://doi.org/10.1002/ppp3.70008)) | Sens correct ; poids relevé (sous-représentée à 4 %) |
| Pente | 2 % | Effet négatif monotone ([Bonet et al. 2010](https://hal.science/hal-00884160v1/document)) | Poids plausible |
| Saison | **0 %** | Fenêtres de fructification (définitionnel) | Garde-fou : plafond 38 hors saison + badge. Pas un poids (uniforme sur la carte) |

**Somme des poids = 100 %** (saison exclue). Les 13 % d'ex-poids saison vont à densité (+6),
géologie (+4) et lisière (+3) — les leviers qui classent les mailles, pas la date.

**Re-challenge saison-gate + poids (24 août 2026).** Emprise Chartreuse 45,22–45,28 N /
5,72–5,80 E, `accessible=0`.

- Cèpe `mode=habitat` : `average=61,9`, `best=91,1` ; 13 mailles ≥ 90 (0,1 %), écart-type 0,35.
  Avant (saison encore à 13 %, densité 10 %) : 117 mailles ≥ 90, `best=92,9`. Sans TCD, plus
  de poids sur FO/FF **resserre** le sommet au lieu de l'étaler — attendu. Relancer après
  `./dev.sh tcd` + `precompute`.
- Cèpe `mode=moment` (24 août, phénologie d'incubation) : tout plafonné à 48. Inchangé.
- Morille `mode=moment` : **toutes** les mailles à 38, libellé « À éviter ». Garde-fou OK.
- Légende / `SuitabilityLevel` **non recalés** : le 90 est plus rare, pas déplacé.

**Note sur le poids météo.** Dans les modèles publiés, la météo explique l'essentiel de la
variabilité **interannuelle**. Ici la carte est spatiale et à date fixe : la météo est quasi
uniforme sur une emprise, donc un poids élevé déplace toute la carte sans discriminer les
secteurs. Les 16 % actuels sont un compromis assumé entre les deux lectures. C'est aussi
pourquoi l'échelle de couleurs du masque « Chance de trouver » est calée sur la moitié haute
de la plage (`LayerLegendFactory`).

### Table des pondérations

La table d'audit ci-dessus fait office de référence. Les valeurs dans le code
(`Criterion::weight()`) doivent rester alignées avec elle.

### Paramètres météo et phénologie

| Paramètre du code | Valeur | Source |
|---|---|---|
| Optimum de température | 9–17 °C (`weatherValue`) | Pic de fructification à ~13 °C en moyenne sur les 20 jours précédents ([suivi décennal de *B. edulis*, hêtraie allemande, 2025](https://doi.org/10.64898/2025.12.12.693895)) |
| Délai de pousse cèpe | min 8 / pic 12 / max 16 j | 10–14 j après pluie abondante ; choc de température du sol sous 14–15 °C initiant les primordia, puis 11–15 j jusqu'à récolte (savoir de terrain, non revu par les pairs) |
| Délai de pousse girolle | min 5 / pic 8 / max 13 j | Espèces à réaction rapide aux orages d'été (savoir de terrain) |
| Seuil d'épisode déclenchant | ≥ 15 mm cumulés, jour pivot ≥ 10 mm | Calibration interne, pas de source directe |
| Fenêtre déclenchante | J−14 → J−5 | **Plus courte que la littérature** : décalage d'un mois pour la pluie ([Karavani et al. 2018](https://www.medfor.eu/sites/default/files/editor/karavani_et_al_final.pdf)), accumulation sur 26 j ([étude 2025](https://doi.org/10.64898/2025.12.12.693895)), température minimale deux semaines plus tôt ([Martínez-Peña 2004](https://oa.upm.es/48556/1/Martinez_2004_CuadSECF.pdf)) |
| Assèchement post-orage | &lt; 8 mm depuis l'épisode **et** sol 3–9 cm &lt; 0,18, en fenêtre de pousse | Phénologie × 0,40 (`FlushClock::POST_STORM_DRYING_FACTOR`) → retombe sous le plafond 62. Savoir de terrain (primordia qui avortent). Inverse de `brokeDrySpell` |

**Libellés UI.** `FlushClock::label` / `explain` convertissent les délais (jours **après l'orage**)
en dates calendaires (`pic vers le 25 août`), à partir du jour de projection `asOf`.
Ne pas les confondre avec le **J+n de la barre du haut** (jours à partir d'aujourd'hui).

## Divergences avec la littérature

### Corrigées

1. **Forme de la courbe de pente.** `SlopeBand` est désormais **monotone décroissante** :
   `toleratedUpTo` marque la fin de la plage bien supportée, pas un optimum, et le plat n'est plus
   pénalisé. Les suivis de récolte ne montrent aucun bonus aux angles intermédiaires.
2. **Pénalité de lisière.** `EdgeAffinity::Indifferent` n'est plus neutre à l'intérieur du
   boisement : la bande de lisière est pénalisée (0,6 au contact, plateau à 120 m), car la
   fructification y chute nettement. La girolle est repassée en `Indifferent` — ses talus sont des
   micro-lisières internes, pas des bordures de massif. `Edge` est réservé aux espèces liées aux
   sols remaniés (morille).
3. **`soilMoisture` utilisé.** Il compte pour 12 % du score météo via `soilMoistureValue()`,
   optimum volumétrique 0,24–0,42 m³/m³.
4. **Fenêtre d'accumulation.** L'historique Open-Meteo passe à 31 jours, ce qui donne
   `accumulatedRainMillimetres` (26 j, relation quasi linéaire avec la fructification) et
   `precedingDryMillimetres`. `waterSupply()` combine épisode déclenchant (65 %) et accumulation
   (35 %).
5. **Amorçage par sécheresse.** `WeatherConditions::brokeDrySpell()` détecte un épisode ≥ 20 mm
   après une quinzaine à moins de 10 mm et majore l'apport en eau de 15 %.
6. **Assèchement après l'orage.** Si la phénologie est en fenêtre de pousse mais qu'il est tombé
   moins de 8 mm depuis l'épisode **et** que le sol 3–9 cm est sous 0,18 m³/m³, la phénologie
   est multipliée par 0,40 (plafond 62). L'orage dans le cumul 26 j ne suffit plus.
   Re-challenge Chartreuse 24 août 2026, cèpe : sol 3–9 cm à 0,26, orage du 21 août encore
   en incubation → pas d'abort. `average` / `best` habitat inchangés ; légende non recalée.

### Restantes

1. **Surface terrière encore proxy.** Le critère « Densité du peuplement » lit Copernicus
   TCD (0–100 %) quand le raster est là, sinon FO/FF. Ce n'est pas des m²/ha mesurés.
   Piste suivante : LIDAR HD IGN (hauteur de canopée). Le NDVI Sentinel-2 sature dans les
   hêtraies/pessières fermées — ne pas en faire la source nominale.
2. **pH réel absent.** La géologie Charm-50 donne un substrat calcaire / siliceux / mixte, pas
   un pH de sol.
3. **Délais de pousse par espèce non sourcés en revue à comité de lecture.** Voir la note en fin
   de fichier.

## Géologie (BRGM Charm-50)

Source : formations `*_S_FGEOL_2154` des départements 26 / 38 / 73, converties via
`./dev.sh geologie` vers `backend/var/geologie/formations.geojsonl`. Classification par mots-clés
sur `DESCR` → `Substrate` (calcaire, siliceux, marneux/mixte, indéterminé). Colonne SQLite
`geology`. Masque « Géologie / substrat ».

## Densité du peuplement (Copernicus TCD)

Remplace le proxy FO/FF du critère densité (16 %), **sans 11ᵉ critère**. Courbe **non monotone**
par espèce (`CanopyDensityBand`) : optimum vers un couvert intermédiaire, pas le plus fermé
(rendement ~15–20 m²/ha, [Bonet et al. 2010](https://doi.org/10.1139/x09-198)).

| | TCD Copernicus | Repli FO/FF |
|---|---|---|
| Déclenchement | `backend/var/tcd/canopy-cover.json` + `.bin` (`./dev.sh tcd`, ou `TCD_PATH`) | raster absent |
| Valeur | 0–100 % au centre de maille 50 m | FO ≈ 25 %, FF ≈ 70 %, inconnu → non peint |
| Licence | Copernicus (accès libre, compte CDSE) | bits 7–8 de `StandCode` |

`./dev.sh tcd` sans argument interroge le catalogue [OData CDSE](https://documentation.dataspace.copernicus.eu/APIs/OData.html)
(`datasetIdentifier` = `clms_vlcc_tree-cover-density_europe_10m_yearly_v1`), télécharge les tuiles
10 m de la dernière année (`TCD_YEAR` pour figer) qui intersectent l'emprise, puis recale sur la
grille 50 m. Auth : `CDSE_USERNAME` / `CDSE_PASSWORD` ou `CDSE_REFRESH_TOKEN` (compte
[dataspace.copernicus.eu](https://dataspace.copernicus.eu)). Tuiles dans `backend/var/tcd/source/`.
Un chemin `.tif` en argument reste possible hors-ligne.

Cache local, **jamais commité** (comme BD Forêt). Colonne SQLite `canopy_cover` (NULL = inconnu).
Les 2 bits FO/FF de `StandCode` restent pour le repli. Masque « Densité du peuplement » continu.

## Précision du couvert forestier

Le couvert pèse **14 %** du score — parmi les plus lourds critères d'habitat — sa qualité
plafonne donc celle du modèle entier.

**Grille à 50 m** (`app.area.cell_size`). C'est le plafond utile face à BD Forêt (≥ 0,5 ha).
Le même pas affine pente, exposition, courbure, distances lisière / eau / accès et donc le score.
Après un changement d'emprise ou de maille : `./dev.sh precompute` puis `export-data`.

**Deux sources, choisies à l'exécution.** `BdForetLandCover` est l'implémentation câblée sur le
port `LandCoverSource`. Elle lit BD Forêt V2 si le jeu converti existe, et délègue sinon à
`OverpassLandCover`. L'hydrographie passe **toujours** par OSM : BD Forêt ne décrit que la
végétation.

| | BD Forêt V2 | OpenStreetMap |
|---|---|---|
| Déclenchement | `backend/var/bdforet/formation-vegetale.geojsonl` présent (ou `BDFORET_PATH`) | sinon |
| Classement | `ForestCover`, `HostTree` et `CanopyClosure` depuis `CODE_TFV` | mêmes enums depuis les tags OSM |
| Granularité | toute plage ≥ 0,5 ha, essence photo-interprétée | tags volontaires, souvent absents |
| Licence | Licence Ouverte 2.0 | ODbL 1.0 |

### Encodage `StandCode` (colonne SQLite `cover`)

Le précalcul remplit une seule grille d'entiers. `StandCode` y empile trois attributs, bits
de poids faible d'abord, pour ne pas changer le schéma :

| Bits | Champ | Valeurs |
|---|---|---|
| 0–2 | `ForestCover` | 0 hors forêt, 1 indéterminé, 2 feuillus, 3 conifères, 4 mixte |
| 3–6 | `HostTree` | 0 inconnue, puis hêtre, chêne, châtaignier, sapin/épicéa, pin, mélèze, douglas, peuplier, robinier, autre feuillu, autre conifère |
| 7–8 | `CanopyClosure` | 0 inconnue, 1 ouverte (`FO`, 10–40 %), 2 fermée (`FF`, plus de 40 %) |

Les archives écrites avant cet encodage ne stockaient que 0–4. Elles restent lisibles :
`StandCode::cover()` prend les 3 bits bas, hôte et densité restent `Unknown`. Un précalcul
nouveau (surtout avec BD Forêt) remplit les bits hauts. La couche « Couvert » de la carte
continue de colorier uniquement la classe 0–4.

`Species::coverSuitability()` combine : affinité feuillu/conifère/mixte (repli) ; affinité
d'essence quand elle est connue. La densité est un critère **séparé**
(`Species::standDensitySuitability()`) : TCD 0–100 % si connu, sinon FO/FF.

**Format attendu.** GeoJSON une ligne par polygone (`ogr2ogr -f GeoJSONSeq`), en WGS84, produit
par `./dev.sh bdforet`. L'adaptateur accepte aussi un `FeatureCollection` classique pour les
petits extraits, mais le streaming ligne à ligne est ce qui permet de passer un département
entier sans saturer la mémoire. Les vecteurs bruts IGN font des dizaines à centaines de Mo :
cache local, **jamais committés**.

**Correspondance TFV → `ForestCover`.** Les niveaux I–II du code donnent la couverture
(`FF` fermée, `FO` ouverte, `FP` peupleraie, `LA` lande), les suivants la composition.

| Code | Classe | Remarque |
|---|---|---|
| `FF1*`, `FO1*`, `FP*` | Feuillus | peupleraie incluse |
| `FF2*`, `FO2*` | Conifères | |
| `FF31`, `FF32`, `FO3*` | Mixte | prépondérance feuillus ou conifères |
| `FF0*`, `FO0*`, `LA*` | Hors forêt | coupe rase, incident, lande : plus d'hôte |
| autre / vide | Indéterminé | |

`FF0` et `FO0` sont volontairement classés hors forêt : la production de carpophores s'effondre
dès que les arbres hôtes disparaissent, même si la parcelle reste juridiquement forestière.

**Limite qui subsiste en mode OSM.** Beaucoup de polygones ne portent aucun tag d'essence et
tombent en `Undetermined`, dont l'affinité vaut 0,60–0,68 pour **toutes** les espèces : ces
mailles ne discriminent rien. `fromOsmTags()` a été élargi (`leaf_cycle`, `taxon`, `species:fr`,
genres et noms français courants) et la requête Overpass accepte `landcover=trees` et
`natural=scrub`, ce qui récupère les cas tagués autrement — mais rien ne peut deviner une essence
non renseignée.

**Corine Land Cover est à écarter** : sa résolution est trop grossière pour la grille de 50 m.

## Saison (garde-fou)

Pas un poids du score : à une date donnée elle est identique sur toute la carte, donc elle
ne classe pas les spots. `Criterion::Season::weight()` vaut **0**. L'UI garde le badge
« En saison / Hors saison » et une ligne d'état (`SeasonAssessment::gateMessage`).

Hors fenêtre : `applyCaps` plafonne à `SeasonAssessment::OUT_OF_SEASON_CAP` (**38**,
« À éviter »). Les morilles en septembre ne peuvent pas lire « Prometteur ». Le mode
« Potentiel d'habitat » ignore ce plafond (et la météo) volontairement.

## Accès (parking + chemin)

Pas un poids du score : filtre d'affichage et masque `access`. Colonne SQLite `access_distance`.

Marche **le long du réseau OSM** depuis une **route carrossable** ou un parking, puis
**500 m d'approche** dans le peuplement (pente > 40° bloquée sur l'approche seulement).
Vol d'oiseau interdit : une crête sans sentier reste hors d'atteinte
(`AccessThreshold::UNREACHABLE` = 9999).

Départ voiture (`OsmWayAccess::isParkable`) : `amenity=parking` et les routes OSM au
rendu **fond blanc / bordure sombre** (Carto, zoom ≥ 13) : `tertiary`, `unclassified`,
`residential`, `living_street`, `road`. Les pistes (`track`), chemins et voies `service`
ne sont **pas** un départ : on y marche seulement. Primaires et secondaires (fond orange /
jaune) non plus — trop larges pour y « se garer » au sens du modèle ; on peut quand même
les emprunter à pied pour joindre un sentier.

Au clic, le même Dijkstra est relancé sur une fenêtre (~2,8 km) à partir des masques
`park` / `path` stockés en SQLite, pour reconstruire le tracé (route ou sentier, puis
approche). L'UI affiche un temps à **4 km/h** sur le coût-mètres déjà pondéré par la pente
(`WALK_METERS_PER_HOUR`) et propose un GPX (centres de maille 50 m).

| Constante | Valeur |
|---|---|
| `AccessThreshold::ALONG_PATH_METERS` | 2 000 m |
| `AccessThreshold::APPROACH_METERS` | 500 m |
| `AccessThreshold::CLIFF_DEGREES` | 40° (approche) |
| `AccessThreshold::WALK_METERS_PER_HOUR` | 4 000 m/h |

Sources OSM : `OsmWayAccess` / Overpass (parkings, routes blanches, sentiers et pistes).
L'interrupteur « Masquer les zones peu accessibles » masque les mailles hors budget sur le
masque potentiel. La propriété privée et la réglementation locale **ne sont pas** modélisées.

## Export GPS (snapshot client)

Pas une couche métier. Le panneau Affichage génère, **dans le navigateur**, un KMZ
(PNG géoréférencé + placemarks des secteurs ≥ 90, nom = indice max) et un GPX (mêmes
points) à partir de la `LayerGrid` déjà affichée — aucun endpoint. Les points sont
triés du meilleur indice au plus faible. Le fichier se périme avec l'espèce, la date
et l'emprise. Locus / AlpineQuest / Iphigénie lisent le KMZ ; Visorando / OsmAnd le GPX.
Le GPX d'itinéraire au clic (`DetailPanel`) reste un chemin d'accès.

## Plafonds du score

`SuitabilityCalculator::applyCaps` empêche l'habitat seul de faire dire n'importe quoi :

| Condition | Plafond |
|---|---|
| Espèce forestière hors forêt | 18 |
| Hors fenêtre saisonnière | 38 |
| Phénologie de pousse < 25 (incubation) | 48 |
| Phénologie de pousse < 45 | 62 |

Les deux derniers existent parce que l'habitat seul pouvait afficher « Très prometteur »
deux jours après l'orage déclenchant, avant toute fructification possible.

## Comment modifier les poids

1. **Re-challenger d'abord** (section ci-dessus) : littérature + emprise / spots d'intérêt.
2. Les poids sont dans `Criterion::weight()`. **Leur somme (hors saison = 0) doit valoir 1,0.**
3. L'interface n'affiche plus `rationale()` (notice du modèle) : seulement `explanation`
   (ce que *ce point* dit). `rationale()` reste la notice mainteneur ; si un poids change,
   elle doit rester vraie ici et dans la table d'audit.
4. Mettre à jour la table d'audit dans ce fichier (colonnes poids + verdict).
5. Les profils par espèce (tranches d'altitude, bandes de pente, affinités de couvert, d'essence
   et de densité, délais de pousse) sont dans `InMemorySpeciesCatalog.php`.
6. Après changement, vérifier l'effet réel sur une emprise connue :
   `GET /api/layer?south=…&layer=potential&species=cepe` et comparer `statistics.average` /
   `statistics.best` avant / après. Un poids qui ne déplace rien sur la carte est un poids inutile.
7. Les seuils de `SuitabilityLevel::fromScore` et les paliers de `LayerLegendFactory` sont
   calés sur la distribution produite par le modèle : **les recaler après toute modification
   de poids**, sinon les libellés perdent leur sens.

## Références

- Bonet, J.A., et al. (2010). *Modelling the production and species richness of wild mushrooms
  in pine forests of the Central Pyrenees.* Annals of Forest Science.
  <https://hal.science/hal-00884160v1/document>
- Martínez-Peña, F., de-Miguel, S., Pukkala, T., Bonet, J.A., et al. (2012). *Yield models for
  ectomycorrhizal mushrooms in Pinus sylvestris forests with special focus on Boletus edulis and
  Lactarius group deliciosus.* Forest Ecology and Management 282:63–69.
  <https://doi.org/10.1016/j.foreco.2012.06.034>
- Martínez-Peña, F. (2004). *Modelización de producciones forestales no leñosas: aplicación a la
  fructificación de Boletus edulis en pinares de silvestre de Soria.* Cuadernos de la SECF.
  <https://oa.upm.es/48556/1/Martinez_2004_CuadSECF.pdf>
- Karavani, A., et al. (2018). *Effect of climatic and micro-climatic conditions on the
  provisioning of fungal-based ecosystem services in Mediterranean pine stands.* Agricultural and
  Forest Meteorology.
  <https://www.medfor.eu/sites/default/files/editor/karavani_et_al_final.pdf>
- Hernández-Rodríguez, M., et al. (2020). *Primary productivity and climate control mushroom
  yields in Mediterranean pine forests.* Agricultural and Forest Meteorology.
  <https://doi.org/10.1016/j.agrformet.2020.108020>
- Rianhard, S., et al. (2025). *Ectomycorrhizal fungal community succession and fragmentation
  across forest edges nearly three decades postharvest.* Plants People Planet.
  <https://doi.org/10.1002/ppp3.70008>
- Luoma, D.L., et al. (2004). *Effects of green-tree retention on sporocarp production.* Forest
  Ecology and Management.
  <https://andrewsforest.oregonstate.edu/data/studies/tp109/luoma2004.pdf>
- *Predicting porcini: a decade of sporocarp monitoring reveals the meteorological triggers of
  Boletus edulis fruiting in central European beech forests* (2025).
  <https://doi.org/10.64898/2025.12.12.693895>
- *A Model of the Current Geographic Distribution and Predictions of Future Range Shifts of
  Lentinula edodes in China* (2025). Journal of Fungi 11:730.
  <https://doi.org/10.3390/jof11100730>

**Attention aux sources non revues par les pairs.** Les délais de pousse par espèce
(cèpe 10–15 j, girolle 5–8 j) viennent du savoir de terrain des cueilleurs, pas d'un article. Ils
sont cohérents avec le seul suivi quotidien publié sur *B. edulis*, mais restent à confronter aux
observations locales (voir la section « Calibrage avec vos spots » du README).
