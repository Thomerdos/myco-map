<script setup lang="ts">
import type { LayerDescriptor, LayerGrid, SpeciesSummary } from '../types'
import { legendGradient } from '../lib/colorScale'

const props = defineProps<{
  layers: LayerDescriptor[]
  species: SpeciesSummary[]
  activeLayer: string
  activeSpecies: string
  opacity: number
  showContours: boolean
  basemap: string
  grid: LayerGrid | null
  loading: boolean
}>()

const emit = defineEmits<{
  'update:activeLayer': [value: string]
  'update:activeSpecies': [value: string]
  'update:opacity': [value: number]
  'update:showContours': [value: boolean]
  'update:basemap': [value: string]
}>()

const BASEMAP_LABELS: Record<string, string> = {
  plan: 'Plan',
  topo: 'Topo',
  satellite: 'Satellite',
}
</script>

<template>
  <div class="panel">
    <section class="block">
      <h2 class="block-title">Espèce recherchée</h2>
      <div class="species-grid">
        <button
          v-for="item in props.species"
          :key="item.id"
          type="button"
          class="species-chip"
          :class="{ active: item.id === props.activeSpecies }"
          @click="emit('update:activeSpecies', item.id)"
        >
          {{ item.name }}
        </button>
      </div>
      <p v-if="props.grid?.species" class="species-detail">
        <em>{{ props.grid.species.scientificName }}</em>
        <span
          class="season-badge"
          :class="props.grid.species.inSeason ? 'in-season' : 'off-season'"
        >
          {{ props.grid.species.inSeason ? 'En saison' : 'Hors saison' }}
        </span>
      </p>
      <p v-if="props.grid?.species?.activeWindow" class="window-note">
        {{ props.grid.species.activeWindow.label }}
      </p>
      <p v-else-if="props.grid?.species?.nextWindow" class="window-note">
        Prochaine fenêtre : {{ props.grid.species.nextWindow.label }}
      </p>
    </section>

    <section class="block">
      <h2 class="block-title">Masque affiché</h2>
      <div class="layer-list">
        <button
          v-for="layer in props.layers"
          :key="layer.id"
          type="button"
          class="layer-row"
          :class="{ active: layer.id === props.activeLayer }"
          @click="emit('update:activeLayer', layer.id)"
        >
          <span>{{ layer.label }}</span>
          <span v-if="layer.unit" class="layer-unit">{{ layer.unit }}</span>
        </button>
      </div>
    </section>

    <section v-if="props.grid" class="block">
      <h2 class="block-title">{{ props.grid.legend.title }}</h2>
      <div v-if="!props.grid.legend.categorical" class="legend-continuous">
        <div class="legend-bar" :style="{ background: legendGradient(props.grid.legend) }" />
        <div class="legend-labels">
          <span v-for="stop in props.grid.legend.stops" :key="stop.value">{{ stop.label }}</span>
        </div>
      </div>
      <ul v-else class="legend-categorical">
        <li v-for="stop in props.grid.legend.stops" :key="stop.value">
          <span class="swatch" :style="{ background: stop.color }" />
          {{ stop.label }}
        </li>
      </ul>
    </section>

    <section class="block">
      <h2 class="block-title">Affichage</h2>
      <label class="control">
        <span>Opacité du masque</span>
        <input
          type="range"
          min="0.2"
          max="1"
          step="0.05"
          :value="props.opacity"
          @input="emit('update:opacity', Number(($event.target as HTMLInputElement).value))"
        />
      </label>
      <label class="control checkbox">
        <input
          type="checkbox"
          :checked="props.showContours"
          @change="emit('update:showContours', ($event.target as HTMLInputElement).checked)"
        />
        <span>Lignes de niveau des zones</span>
      </label>
      <div class="basemap-row">
        <button
          v-for="(label, key) in BASEMAP_LABELS"
          :key="key"
          type="button"
          class="basemap-chip"
          :class="{ active: key === props.basemap }"
          @click="emit('update:basemap', key)"
        >
          {{ label }}
        </button>
      </div>
    </section>

    <section v-if="props.grid" class="block">
      <h2 class="block-title">Conditions du moment</h2>
      <ul class="facts">
        <li>
          <strong>{{ props.grid.weather.triggerRain }} mm</strong>
          de pluie déclenchante (J-14 à J-5)
        </li>
        <li>
          <strong>{{ props.grid.weather.recentRain }} mm</strong>
          ces 5 derniers jours
        </li>
        <li><strong>{{ props.grid.weather.temperature }} °C</strong> en moyenne</li>
        <li><strong>{{ props.grid.weather.humidity }} %</strong> d'humidité de l'air</li>
        <li class="verdict">{{ props.grid.weather.label }}</li>
      </ul>
      <p v-if="props.grid.weather.degraded" class="degraded">
        Données météo indisponibles, valeurs de repli utilisées.
      </p>
      <p class="resolution">
        Maille affichée : {{ props.grid.statistics.resolution }} m ·
        {{ props.grid.statistics.cells.toLocaleString('fr-FR') }} mailles
        <span v-if="props.loading"> · calcul en cours…</span>
      </p>
    </section>
  </div>
</template>

<style scoped>
.panel {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
  padding: 1rem 1.05rem 2rem;
  overflow-y: auto;
  background: #f7f4ec;
  border-right: 1px solid #ded6c4;
}

.block-title {
  margin: 0 0 0.55rem;
  font-size: 0.74rem;
  font-weight: 700;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: #6a6153;
}

.species-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.35rem;
}

.species-chip,
.basemap-chip {
  padding: 0.42rem 0.5rem;
  border: 1px solid #d4cbb7;
  border-radius: 7px;
  background: #fffdf8;
  color: #2f2a22;
  font-size: 0.82rem;
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s;
}

.species-chip:hover,
.basemap-chip:hover {
  border-color: #14342a;
}

.species-chip.active,
.basemap-chip.active {
  background: #14342a;
  border-color: #14342a;
  color: #f4f1e6;
  font-weight: 600;
}

.species-detail {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin: 0.6rem 0 0;
  font-size: 0.8rem;
  color: #5d5648;
}

.season-badge {
  padding: 0.12rem 0.45rem;
  border-radius: 999px;
  font-size: 0.7rem;
  font-weight: 700;
  white-space: nowrap;
}

.in-season {
  background: #d6ecd9;
  color: #1c6b3a;
}

.off-season {
  background: #f2e2c4;
  color: #8a5a12;
}

.window-note {
  margin: 0.35rem 0 0;
  font-size: 0.79rem;
  color: #4a443a;
}

.layer-list {
  display: flex;
  flex-direction: column;
  gap: 0.22rem;
}

.layer-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.42rem 0.55rem;
  border: 1px solid transparent;
  border-radius: 7px;
  background: #fffdf8;
  color: #2f2a22;
  font-size: 0.83rem;
  text-align: left;
  cursor: pointer;
}

.layer-row:hover {
  border-color: #c6bda8;
}

.layer-row.active {
  background: #e3ecdf;
  border-color: #14342a;
  font-weight: 600;
}

.layer-unit {
  font-size: 0.72rem;
  color: #7d7463;
}

.legend-bar {
  height: 12px;
  border-radius: 6px;
  border: 1px solid #cfc6b1;
}

.legend-labels {
  display: flex;
  justify-content: space-between;
  gap: 0.3rem;
  margin-top: 0.3rem;
  font-size: 0.68rem;
  color: #6a6153;
}

.legend-categorical {
  display: flex;
  flex-direction: column;
  gap: 0.28rem;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 0.8rem;
}

.legend-categorical li {
  display: flex;
  align-items: center;
  gap: 0.45rem;
}

.swatch {
  width: 14px;
  height: 14px;
  border-radius: 3px;
  border: 1px solid #b9b1a0;
}

.control {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.79rem;
  color: #4a443a;
}

.control input[type='range'] {
  width: 100%;
}

.control.checkbox {
  flex-direction: row;
  align-items: center;
  gap: 0.45rem;
  margin-top: 0.55rem;
}

.basemap-row {
  display: flex;
  gap: 0.3rem;
  margin-top: 0.7rem;
}

.basemap-row .basemap-chip {
  flex: 1;
  font-size: 0.76rem;
}

.facts {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.81rem;
  color: #4a443a;
}

.verdict {
  margin-top: 0.2rem;
  font-weight: 700;
  color: #14342a;
}

.degraded,
.resolution {
  margin: 0.5rem 0 0;
  font-size: 0.72rem;
  color: #7d7463;
}
</style>
