<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import ControlPanel from './components/ControlPanel.vue'
import DetailPanel from './components/DetailPanel.vue'
import MycoMap from './components/MycoMap.vue'
import { fetchContext, fetchLayer, fetchLocation } from './lib/api'
import type { Bounds, LayerGrid, LocationReport, MapContext } from './types'

const context = ref<MapContext | null>(null)
const grid = ref<LayerGrid | null>(null)
const report = ref<LocationReport | null>(null)

const activeLayer = ref('potential')
const activeSpecies = ref('cepe')
const opacity = ref(0.72)
const showContours = ref(false)
// Listed in the drawer only — numbered pins that recompute on zoom looked like moving clusters.
const showSpots = ref(false)
const basemap = ref('topo')
const selectedPoint = ref<{ lat: number; lng: number } | null>(null)
const detailOpen = ref(false)
const controlsOpen = ref(false)

const loadingLayer = ref(false)
const loadingReport = ref(false)
const error = ref<string | null>(null)
const notReady = ref(false)

let viewport: Bounds | null = null
let reloadTimer: number | undefined
let requestToken = 0

const mapCenter = computed(() => context.value?.area.center ?? { lat: 45.19, lng: 5.79 })
const mapZoom = computed(() => context.value?.area.zoom ?? 11)
const weatherChip = computed(() => grid.value?.weather ?? null)
const activeSpeciesMeta = computed(
  () => context.value?.species.find((item) => item.id === activeSpecies.value) ?? null,
)

onMounted(async () => {
  try {
    const loaded = await fetchContext()
    context.value = loaded
    notReady.value = !loaded.ready
    if (loaded.ready && viewport) {
      await loadLayer(viewport)
    }
  } catch (cause) {
    error.value = "Impossible de joindre l'API. Vérifiez que le backend tourne sur le port 8765."
    console.error(cause)
  }
})

function onViewportChanged(bounds: Bounds) {
  viewport = bounds
  window.clearTimeout(reloadTimer)
  reloadTimer = window.setTimeout(() => {
    if (viewport && context.value?.ready) {
      void loadLayer(viewport)
    }
  }, 280)
}

async function loadLayer(bounds: Bounds) {
  const token = ++requestToken
  loadingLayer.value = true
  error.value = null

  try {
    const result = await fetchLayer({
      layer: activeLayer.value,
      species: activeSpecies.value,
      bounds,
      maxCells: 45000,
    })
    if (token === requestToken) {
      grid.value = result
    }
  } catch (cause) {
    if (token === requestToken) {
      error.value = cause instanceof Error ? cause.message : 'Erreur de chargement du masque.'
    }
  } finally {
    if (token === requestToken) {
      loadingLayer.value = false
    }
  }
}

async function onLocationPicked(point: { lat: number; lng: number }) {
  selectedPoint.value = point
  detailOpen.value = true
  loadingReport.value = true

  try {
    report.value = await fetchLocation({
      lat: point.lat,
      lng: point.lng,
      species: activeSpecies.value,
    })
  } catch (cause) {
    report.value = null
    console.error(cause)
  } finally {
    loadingReport.value = false
  }
}

function onLayerChange(layer: string) {
  activeLayer.value = layer
  if (viewport) void loadLayer(viewport)
}

function onSpeciesChange(species: string) {
  activeSpecies.value = species
  if (viewport) void loadLayer(viewport)
  if (selectedPoint.value) void onLocationPicked(selectedPoint.value)
}
</script>

<template>
  <div class="shell">
    <div class="map-stage">
      <MycoMap
        :grid="grid"
        :center="mapCenter"
        :zoom="mapZoom"
        :opacity="opacity"
        :show-contours="showContours"
        :show-spots="showSpots"
        :basemap="basemap"
        :selected="selectedPoint"
        @viewport-changed="onViewportChanged"
        @location-picked="onLocationPicked"
      />

      <header class="top-float">
        <div class="brand">
          <strong>Myco Map</strong>
          <span>{{ context?.area.name ?? 'Grenoble' }}</span>
        </div>

        <div class="toolbar">
          <label class="field">
            <span>Espèce</span>
            <select
              :value="activeSpecies"
              @change="onSpeciesChange(($event.target as HTMLSelectElement).value)"
            >
              <option v-for="item in context?.species ?? []" :key="item.id" :value="item.id">
                {{ item.name }}
              </option>
            </select>
          </label>

          <label class="field">
            <span>Masque</span>
            <select
              :value="activeLayer"
              @change="onLayerChange(($event.target as HTMLSelectElement).value)"
            >
              <option v-for="layer in context?.layers ?? []" :key="layer.id" :value="layer.id">
                {{ layer.label }}
              </option>
            </select>
          </label>

          <button type="button" class="ghost" @click="controlsOpen = !controlsOpen">
            Affichage
          </button>
        </div>

        <div class="status-row">
          <span v-if="loadingLayer" class="chip loading">Calcul…</span>
          <span v-else-if="weatherChip" class="chip weather">{{ weatherChip.label }}</span>
          <span
            v-if="grid?.species"
            class="chip"
            :class="grid.species.inSeason ? 'in-season' : 'off-season'"
          >
            {{ grid.species.inSeason ? 'En saison' : 'Hors saison' }}
          </span>
        </div>
      </header>

      <aside v-if="controlsOpen" class="controls-float">
        <ControlPanel
          :layers="context?.layers ?? []"
          :species="context?.species ?? []"
          :active-layer="activeLayer"
          :active-species="activeSpecies"
          :opacity="opacity"
          :show-contours="showContours"
          :show-spots="showSpots"
          :basemap="basemap"
          :grid="grid"
          :loading="loadingLayer"
          @update:active-layer="onLayerChange"
          @update:active-species="onSpeciesChange"
          @update:opacity="opacity = $event"
          @update:show-contours="showContours = $event"
          @update:show-spots="showSpots = $event"
          @update:basemap="basemap = $event"
        />
      </aside>

      <div v-if="grid && !grid.legend.categorical" class="legend-float">
        <span class="legend-title">{{ grid.legend.title }}</span>
        <div
          class="legend-bar"
          :style="{
            background: `linear-gradient(to right, ${grid.legend.stops.map((stop) => stop.color).join(', ')})`,
          }"
        />
        <div class="legend-ends">
          <span>{{ grid.legend.stops[0]?.label }}</span>
          <span>{{ grid.legend.stops[grid.legend.stops.length - 1]?.label }}</span>
        </div>
      </div>

      <p v-if="error" class="banner error">{{ error }}</p>
      <p v-else-if="notReady" class="banner warning">
        Données non précalculées — lancez <code>./dev.sh restore-data</code>.
      </p>

      <p v-if="activeSpeciesMeta" class="hint-float">
        {{ activeSpeciesMeta.summary }}
      </p>
    </div>

    <aside class="detail-drawer" :class="{ open: detailOpen }">
      <button type="button" class="drawer-close" @click="detailOpen = false">Fermer</button>
      <DetailPanel
        :report="report"
        :highlights="grid?.highlights ?? []"
        :loading="loadingReport"
        @highlight-picked="onLocationPicked"
      />
    </aside>
  </div>
</template>

<style>
:root {
  --font-sans: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
  font-family: var(--font-sans);
  font-synthesis: none;
  text-rendering: optimizeLegibility;
  -webkit-font-smoothing: antialiased;
  color: #2b261f;
  background: #1a2420;
}

* {
  box-sizing: border-box;
}

body {
  margin: 0;
}

#app {
  min-height: 100vh;
}

button,
select,
input {
  font: inherit;
}
</style>

<style scoped>
.shell {
  position: relative;
  height: 100vh;
  overflow: hidden;
}

.map-stage {
  position: absolute;
  inset: 0;
}

.top-float {
  position: absolute;
  top: 0.75rem;
  left: 0.75rem;
  right: 0.75rem;
  z-index: 1000;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.65rem 1rem;
  padding: 0.65rem 0.85rem;
  border-radius: 14px;
  background: rgb(250 247 240 / 94%);
  border: 1px solid #d9d0bc;
  box-shadow: 0 8px 28px rgb(20 30 24 / 18%);
  backdrop-filter: blur(8px);
}

.brand {
  display: flex;
  flex-direction: column;
  min-width: 8rem;
}

.brand strong {
  font-size: 1.1rem;
  font-weight: 700;
  letter-spacing: -0.03em;
  color: #14342a;
}

.brand span {
  font-size: 0.72rem;
  color: #6a6153;
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  flex: 1;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.12rem;
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #7d7463;
}

.field select,
.ghost {
  padding: 0.35rem 0.55rem;
  border: 1px solid #cfc6b1;
  border-radius: 8px;
  background: #fffdf8;
  color: #2b261f;
  text-transform: none;
  letter-spacing: 0;
  font-size: 0.84rem;
  cursor: pointer;
}

.ghost {
  align-self: end;
}

.status-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.chip {
  padding: 0.28rem 0.55rem;
  border-radius: 999px;
  background: #e8e1d0;
  font-size: 0.72rem;
  color: #3f3a31;
}

.chip.loading {
  background: #c98a3a;
  color: #2b1c07;
  font-weight: 700;
}

.chip.weather {
  background: #14342a;
  color: #f4f1e6;
}

.chip.in-season {
  background: #d6ecd9;
  color: #1c6b3a;
}

.chip.off-season {
  background: #f2e2c4;
  color: #8a5a12;
}

.controls-float {
  position: absolute;
  top: 5.2rem;
  left: 0.75rem;
  z-index: 1000;
  width: min(300px, calc(100vw - 1.5rem));
  max-height: calc(100vh - 6.5rem);
  overflow: auto;
  border-radius: 14px;
  box-shadow: 0 10px 30px rgb(20 30 24 / 22%);
}

.legend-float {
  position: absolute;
  left: 0.75rem;
  bottom: 1.4rem;
  z-index: 900;
  width: min(240px, calc(100vw - 1.5rem));
  padding: 0.55rem 0.7rem;
  border-radius: 10px;
  background: rgb(250 247 240 / 92%);
  border: 1px solid #d9d0bc;
}

.legend-title {
  display: block;
  margin-bottom: 0.3rem;
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #6a6153;
}

.legend-bar {
  height: 10px;
  border-radius: 6px;
  border: 1px solid #cfc6b1;
}

.legend-ends {
  display: flex;
  justify-content: space-between;
  margin-top: 0.25rem;
  font-size: 0.68rem;
  color: #7d7463;
}

.hint-float {
  position: absolute;
  right: 0.75rem;
  bottom: 1.4rem;
  z-index: 900;
  max-width: min(320px, calc(100vw - 2rem));
  margin: 0;
  padding: 0.55rem 0.7rem;
  border-radius: 10px;
  background: rgb(20 52 42 / 88%);
  color: #f4f1e6;
  font-size: 0.75rem;
  line-height: 1.4;
}

.banner {
  position: absolute;
  top: 5.2rem;
  left: 50%;
  transform: translateX(-50%);
  z-index: 1100;
  margin: 0;
  padding: 0.55rem 0.9rem;
  border-radius: 8px;
  font-size: 0.82rem;
}

.banner.error {
  background: #f8e0dc;
  color: #8a2c22;
}

.banner.warning {
  background: #f6ecd2;
  color: #7a5312;
}

.detail-drawer {
  position: absolute;
  top: 0;
  right: 0;
  z-index: 1200;
  width: min(360px, 100vw);
  height: 100%;
  transform: translateX(105%);
  transition: transform 0.22s ease;
  background: #fbf9f3;
  box-shadow: -8px 0 28px rgb(0 0 0 / 18%);
}

.detail-drawer.open {
  transform: translateX(0);
}

.drawer-close {
  position: absolute;
  top: 0.55rem;
  right: 0.55rem;
  z-index: 2;
  padding: 0.3rem 0.55rem;
  border: 1px solid #cfc6b1;
  border-radius: 7px;
  background: #fffdf8;
  cursor: pointer;
  font-size: 0.78rem;
}

.detail-drawer :deep(.panel) {
  height: 100%;
  border-left: none;
  padding-top: 2.4rem;
}

@media (max-width: 720px) {
  .hint-float {
    display: none;
  }

  .detail-drawer {
    width: 100vw;
  }
}
</style>
