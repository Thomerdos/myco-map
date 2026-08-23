# AGENTS.md

Notes de travail pour les agents (et les humains) qui interviennent sur ce dépôt.

## Conventions du dépôt

- **Langue** : tout ce qui est visible par l'utilisateur (libellés, explications, messages de commit, README) est en **français**. Les commentaires de code sont en **anglais**.
- **Backend** : Symfony 7 / PHP 8.5, domaine sans dépendance à l'infrastructure (`backend/src/Domain`), adaptateurs dans `backend/src/Infrastructure`.
- **Frontend** : Vue 3 + Vite + Leaflet, TypeScript strict. Police unique : Inter.
- **Vérification avant commit** : `cd frontend && npm run build` (inclut `vue-tsc`). Le backend n'a pas de suite de tests.
- **Serveurs de dev** : `./dev.sh backend` (port 8765) et `./dev.sh frontend` (port 43123).

## Modèle de score : pondérations et sources

Le score est une somme pondérée de huit critères (`backend/src/Domain/Mycology/Criterion.php`),
suivie de plafonds (`SuitabilityCalculator::applyCaps`).

### Statut honnête des poids

**Ces poids sont des priors d'expert, pas des coefficients ajustés sur des données d'observation.**
Aucun jeu de données de récolte géolocalisée n'alimente ce dépôt. La littérature citée ci-dessous
valide le **classement** et le **sens** des effets, pas les pourcentages exacts. Les meilleurs
modèles publiés de rendement à partir de la seule météo plafonnent autour de R² ≈ 0,22
([Martínez-Peña et al. 2012](https://doi.org/10.1016/j.foreco.2012.06.034)) : viser une précision
meilleure que ~5 points sur un poids n'a pas de sens.

### Table des pondérations

| Critère | Poids | Appui dans la littérature | Verdict |
|---|---|---|---|
| Saison | 16 % | Fenêtres de fructification par espèce (définitionnel) | Cohérent, non sourçable comme « poids » |
| Météo / rythme de pousse | 22 % | Pluie et température significatives dans **tous** les modèles de rendement ajustés ([Martínez-Peña et al. 2012](https://doi.org/10.1016/j.foreco.2012.06.034), [Karavani et al. 2018](https://www.medfor.eu/sites/default/files/editor/karavani_et_al_final.pdf)) | Défendable pour une carte spatiale (voir note ci-dessous) |
| Couvert forestier | 18 % | Dépendance ectomycorhizienne à l'hôte ; dans les modèles MaxEnt la présence de l'hôte est le premier contributeur ([Lentinula edodes, J. Fungi 2025](https://doi.org/10.3390/jof11100730)) | Bien appuyé |
| Altitude | 15 % | Facteur de site significatif ; l'altitude croissante augmente la production ([Bonet et al. 2010](https://hal.science/hal-00884160v1/document)) ; 34,3 % de contribution MaxEnt pour *Cortinarius sinensis* | Bien appuyé |
| Exposition | 15 % | Versants nord = production la plus forte, sud la plus faible ([Bonet et al. 2010](https://hal.science/hal-00884160v1/document)) ; l'humidité du sol et la faible lumière ressortent comme facteurs premiers d'abondance | Bien appuyé (sens certain, ampleur estimée) |
| Humidité topographique | 8 % | L'humidité du sol télédétectée rivalise avec la pluie comme prédicteur (r = 0,63–0,72, [Hernández-Rodríguez et al. 2020](https://doi.org/10.1016/j.agrformet.2020.108020)) | **Probablement sous-pondéré** |
| Position lisière | 4 % | Fructification fortement réduite en lisière : −65 % de richesse en carpophores vs intérieur ([Rianhard et al. 2025](https://doi.org/10.1002/ppp3.70008)) ; [Luoma et al. 2004](https://andrewsforest.oregonstate.edu/data/studies/tp109/luoma2004.pdf) | Sens correct pour `Interior`, **ampleur sous-modélisée** |
| Pente | 2 % | Significative mais **effet négatif monotone** : la pente croissante diminue la production ([Bonet et al. 2010](https://hal.science/hal-00884160v1/document)) | Poids plausible, **forme de courbe divergente** |

**Note sur le poids météo.** Dans les modèles publiés, la météo explique l'essentiel de la
variabilité **interannuelle**. Ici la carte est spatiale et à date fixe : la météo est quasi
uniforme sur une emprise, donc un poids élevé déplace toute la carte sans discriminer les
secteurs. Les 22 % actuels sont un compromis assumé entre les deux lectures. C'est aussi
pourquoi l'échelle de couleurs du masque « Chance de trouver » est calée sur la moitié haute
de la plage (`LayerLegendFactory`).

### Paramètres météo et phénologie

| Paramètre du code | Valeur | Source |
|---|---|---|
| Optimum de température | 9–17 °C (`weatherValue`) | Pic de fructification à ~13 °C en moyenne sur les 20 jours précédents ([suivi décennal de *B. edulis*, hêtraie allemande, 2025](https://doi.org/10.64898/2025.12.12.693895)) |
| Délai de pousse cèpe | min 8 / pic 12 / max 16 j | 10–14 j après pluie abondante ; choc de température du sol sous 14–15 °C initiant les primordia, puis 11–15 j jusqu'à récolte (savoir de terrain, non revu par les pairs) |
| Délai de pousse girolle | min 5 / pic 8 / max 13 j | Espèces à réaction rapide aux orages d'été (savoir de terrain) |
| Seuil d'épisode déclenchant | ≥ 15 mm cumulés, jour pivot ≥ 10 mm | Calibration interne, pas de source directe |
| Fenêtre déclenchante | J−14 → J−5 | **Plus courte que la littérature** : décalage d'un mois pour la pluie ([Karavani et al. 2018](https://www.medfor.eu/sites/default/files/editor/karavani_et_al_final.pdf)), accumulation sur 26 j ([étude 2025](https://doi.org/10.64898/2025.12.12.693895)), température minimale deux semaines plus tôt ([Martínez-Peña 2004](https://oa.upm.es/48556/1/Martinez_2004_CuadSECF.pdf)) |

## Divergences connues avec la littérature

À traiter ou à assumer explicitement, pas à oublier :

1. **Forme de la courbe de pente.** `SlopeBand` donne un plateau à 1,0 entre `optimumLow` et
   `optimumHigh` et pénalise le plat. La littérature décrit un effet **négatif monotone** de la
   pente, sans bonus pour les pentes moyennes. La pénalité du plat repose sur un raisonnement
   physique (litière compactée ou gorgée) non sourcé.
2. **`EdgeAffinity::Edge` pour la girolle.** Contraire au signal ectomycorhizien général
   (fructification réduite en lisière). L'observation de terrain porte sur des **micro-lisières**
   (talus de sentier, bord de piste), pas sur la lisière de massif que mesure `edgeDistance`.
   Ambiguïté à lever.
3. **`soilMoisture` récupéré mais jamais noté.** Open-Meteo fournit
   `soil_moisture_0_to_1cm`, exposé dans l'API, absent du calcul — alors que c'est un
   prédicteur aussi fort que la pluie.
4. **Densité du peuplement absente.** La surface terrière est la variable de peuplement la plus
   citée, avec un optimum autour de 15–20 m²/ha. Rien d'équivalent n'est modélisé (OSM ne le
   donne pas ; piste : NDVI Sentinel-2 ou BD Forêt V2).
5. **Sol et pH absents.** Comptent pour la trompette (calcaire) et la morille.
6. **Pas d'amorçage par sécheresse.** Un épisode marqué après une longue période sèche est plus
   productif ; le modèle traite tous les épisodes de même cumul à l'identique.

## Plafonds du score

`SuitabilityCalculator::applyCaps` empêche l'habitat seul de faire dire n'importe quoi :

| Condition | Plafond |
|---|---|
| Espèce forestière hors forêt | 18 |
| Hors fenêtre saisonnière | 38 |
| Phénologie de pousse < 25 (incubation) | 48 |
| Phénologie de pousse < 45 | 62 |

Les deux derniers existent parce que saison + habitat pouvaient afficher « Très prometteur »
deux jours après l'orage déclenchant, avant toute fructification possible.

## Comment modifier les poids

1. Les poids sont dans `Criterion::weight()`. **Leur somme doit valoir 1,0.**
2. `Criterion::rationale()` est affiché dans l'interface sous chaque critère : si un poids
   change, l'explication doit rester vraie.
3. Les profils par espèce (tranches d'altitude, bandes de pente, affinités de couvert, délais de
   pousse) sont dans `InMemorySpeciesCatalog.php`.
4. Après changement, vérifier l'effet réel sur une emprise connue :
   `GET /api/layer?south=…&layer=potential&species=cepe` et comparer `statistics.average` /
   `statistics.best` avant / après. Un poids qui ne déplace rien sur la carte est un poids inutile.
5. Les seuils de `SuitabilityLevel::fromScore` et les paliers de `LayerLegendFactory` sont
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
