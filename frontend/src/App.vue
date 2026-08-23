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
const basemap = ref('topo')
const selectedPoint = ref<{ lat: number; lng: number } | null>(null)

const loadingLayer = ref(false)
const loadingReport = ref(false)
const error = ref<string | null>(null)
const notReady = ref(false)

let viewport: Bounds | null = null
let reloadTimer: number | undefined
let requestToken = 0

const mapCenter = computed(() => context.value?.area.center ?? { lat: 45.19, lng: 5.79 })
const mapZoom = computed(() => context.value?.area.zoom ?? 11)

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
  scheduleReload()
}

function scheduleReload() {
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
    <header class="topbar">
      <div class="brand">
        <h1>Myco Map</h1>
        <p>{{ context?.area.name ?? 'Grenoble — Chartreuse, Belledonne, Vercors' }}</p>
      </div>
      <div class="status">
        <span v-if="loadingLayer" class="pill loading">Calcul du masque…</span>
        <span v-else-if="grid" class="pill">
          {{ grid.layerLabel }} · maille {{ grid.statistics.resolution }} m
        </span>
      </div>
    </header>

    <p v-if="error" class="banner error">{{ error }}</p>
    <p v-else-if="notReady" class="banner warning">
      Les données ne sont pas encore précalculées. Lancez
      <code>php bin/console app:precompute</code> dans le dossier <code>backend</code>.
    </p>

    <main class="layout">
      <ControlPanel
        :layers="context?.layers ?? []"
        :species="context?.species ?? []"
        :active-layer="activeLayer"
        :active-species="activeSpecies"
        :opacity="opacity"
        :show-contours="showContours"
        :basemap="basemap"
        :grid="grid"
        :loading="loadingLayer"
        @update:active-layer="onLayerChange"
        @update:active-species="onSpeciesChange"
        @update:opacity="opacity = $event"
        @update:show-contours="showContours = $event"
        @update:basemap="basemap = $event"
      />

      <section class="map-holder">
        <MycoMap
          :grid="grid"
          :center="mapCenter"
          :zoom="mapZoom"
          :opacity="opacity"
          :show-contours="showContours"
          :basemap="basemap"
          :selected="selectedPoint"
          @viewport-changed="onViewportChanged"
          @location-picked="onLocationPicked"
        />
      </section>

      <DetailPanel
        :report="report"
        :highlights="grid?.highlights ?? []"
        :loading="loadingReport"
        @highlight-picked="onLocationPicked"
      />
    </main>
  </div>
</template>

<style>
:root {
  font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
  color: #2b261f;
  background: #ece7dc;
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
</style>

<style scoped>
.shell {
  display: flex;
  flex-direction: column;
  height: 100vh;
}

.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.7rem 1.1rem;
  background: #14342a;
  color: #f2efe4;
}

.brand h1 {
  margin: 0;
  font-size: 1.15rem;
  letter-spacing: 0.02em;
}

.brand p {
  margin: 0.12rem 0 0;
  font-size: 0.78rem;
  opacity: 0.82;
}

.pill {
  padding: 0.28rem 0.6rem;
  border-radius: 999px;
  background: rgb(255 255 255 / 12%);
  font-size: 0.74rem;
}

.pill.loading {
  background: #c98a3a;
  color: #2b1c07;
  font-weight: 600;
}

.banner {
  margin: 0;
  padding: 0.55rem 1.1rem;
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

.banner code {
  background: rgb(0 0 0 / 8%);
  padding: 0.05rem 0.3rem;
  border-radius: 4px;
}

.layout {
  flex: 1;
  min-height: 0;
  display: grid;
  grid-template-columns: minmax(240px, 288px) 1fr minmax(260px, 340px);
}

.map-holder {
  position: relative;
  min-height: 0;
}

@media (max-width: 1100px) {
  .layout {
    grid-template-columns: minmax(220px, 260px) 1fr;
  }

  .layout > :last-child {
    grid-column: 1 / -1;
    border-left: none;
    border-top: 1px solid #ded6c4;
    max-height: 45vh;
  }
}

@media (max-width: 760px) {
  .shell {
    height: auto;
    min-height: 100vh;
  }

  .layout {
    grid-template-columns: 1fr;
  }

  .map-holder {
    height: 58vh;
  }

  .layout > :first-child {
    border-right: none;
    border-bottom: 1px solid #ded6c4;
  }
}
</style>
