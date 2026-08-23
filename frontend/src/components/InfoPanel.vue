<script setup lang="ts">
import type { MapCell, MapResponse } from '../types'
import { forestLabel, levelLabel } from '../utils/colors'

defineProps<{
  cell: MapCell | null
  mapData: MapResponse | null
  loading: boolean
}>()
</script>

<template>
  <aside class="panel">
    <section v-if="mapData" class="block">
      <h2>{{ mapData.species.name }}</h2>
      <p class="scientific">{{ mapData.species.scientificName }}</p>
      <p class="desc">{{ mapData.species.description }}</p>

      <div class="badge-row">
        <span :class="['badge', mapData.species.inSeason ? 'ok' : 'warn']">
          {{ mapData.species.inSeason ? 'En saison' : 'Hors saison' }}
        </span>
        <span class="badge neutral">Météo : {{ mapData.weather.label }}</span>
      </div>

      <div v-if="mapData.species.activeWindow" class="window">
        Fenêtre active : {{ mapData.species.activeWindow.label }}
      </div>

      <h3>Fenêtres de cueillette</h3>
      <ul class="windows">
        <li v-for="(w, i) in mapData.species.harvestWindows" :key="i">
          {{ w.label }}
          <span v-if="w.peak" class="peak">pic</span>
        </li>
      </ul>

      <h3>Météo récente</h3>
      <ul class="facts">
        <li>{{ mapData.weather.precipitation7d }} mm / 7 j</li>
        <li>{{ mapData.weather.avgTemp }} °C moy.</li>
        <li>{{ mapData.weather.humidity }} % humidité</li>
      </ul>
    </section>

    <section class="block">
      <h3>Zone sélectionnée</h3>
      <div v-if="loading" class="muted">Analyse en cours…</div>
      <div v-else-if="cell" class="cell-detail">
        <div class="score-line">
          <strong>{{ cell.score.toFixed(0) }}</strong>
          <span>/ 100 — {{ levelLabel(cell.level) }}</span>
        </div>
        <ul v-if="cell.terrain" class="facts">
          <li>{{ cell.terrain.elevation }} m d'altitude</li>
          <li>Pente {{ cell.terrain.slope }}° — exposition {{ cell.terrain.aspectLabel }}</li>
          <li>{{ forestLabel(cell.terrain.forestType) }}</li>
        </ul>
        <h4>Pourquoi cette zone ?</h4>
        <ul class="reasons">
          <li v-for="(reason, idx) in cell.reasons" :key="idx">{{ reason }}</li>
        </ul>
      </div>
      <p v-else class="muted">Cliquez sur une maille ou un cercle pour voir le détail.</p>
    </section>

    <section v-if="mapData" class="block stats">
      <h3>Statistiques zone visible</h3>
      <p>{{ mapData.stats.count }} mailles · moy. {{ mapData.stats.avgScore }} · max {{ mapData.stats.topScore }}</p>
    </section>
  </aside>
</template>

<style scoped>
.panel {
  background: #f7f5f0;
  border-left: 1px solid #ddd6c8;
  overflow-y: auto;
  padding: 1rem 1.1rem 2rem;
}

.block + .block {
  margin-top: 1.25rem;
  padding-top: 1rem;
  border-top: 1px solid #e5dfd3;
}

h2 {
  margin: 0;
  font-size: 1.35rem;
}

h3 {
  margin: 0.9rem 0 0.4rem;
  font-size: 0.95rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #5c5346;
}

.scientific {
  margin: 0.15rem 0 0.5rem;
  font-style: italic;
  color: #6b6358;
}

.desc {
  margin: 0;
  line-height: 1.45;
  color: #3f3a33;
}

.badge-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-top: 0.75rem;
}

.badge {
  font-size: 0.78rem;
  padding: 0.25rem 0.55rem;
  border-radius: 999px;
  font-weight: 600;
}

.badge.ok {
  background: #d7f0e0;
  color: #1b7f4a;
}

.badge.warn {
  background: #fdebd0;
  color: #9a6700;
}

.badge.neutral {
  background: #e8eef5;
  color: #355070;
}

.window {
  margin-top: 0.6rem;
  font-size: 0.9rem;
}

.windows,
.facts,
.reasons {
  margin: 0.2rem 0 0;
  padding-left: 1.1rem;
  line-height: 1.5;
}

.peak {
  margin-left: 0.35rem;
  font-size: 0.7rem;
  background: #1b7f4a;
  color: #fff;
  padding: 0.05rem 0.35rem;
  border-radius: 4px;
}

.score-line {
  font-size: 1.1rem;
  margin-bottom: 0.5rem;
}

.score-line strong {
  font-size: 1.6rem;
  color: #1b7f4a;
}

.muted {
  color: #7a7268;
  font-size: 0.92rem;
}

.stats p {
  margin: 0;
  font-size: 0.9rem;
}
</style>
