<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import ForageMap from './components/ForageMap.vue'
import InfoPanel from './components/InfoPanel.vue'
import { fetchMap, fetchRegions, fetchSpecies } from './api'
import type { MapCell, MapResponse, MapViewMode, Region, Species } from './types'

const regions = ref<Region[]>([])
const speciesList = ref<Species[]>([])
const selectedRegion = ref('chartreuse')
const selectedSpecies = ref('cepe')
const viewMode = ref<MapViewMode>('grid')
const resolution = ref(500)
const mapData = ref<MapResponse | null>(null)
const selectedCell = ref<MapCell | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

const currentRegion = computed(() => regions.value.find((r) => r.id === selectedRegion.value))
const currentBounds = ref<{ south: number; west: number; north: number; east: number } | null>(null)
const pendingInitialLoad = ref(true)

onMounted(async () => {
  try {
    regions.value = await fetchRegions()
    speciesList.value = await fetchSpecies()
    if (currentRegion.value) {
      currentBounds.value = { ...currentRegion.value.bounds }
    }
  } catch (e) {
    error.value = 'Impossible de contacter l\'API backend.'
    console.error(e)
  }
})

watch([selectedRegion, selectedSpecies, resolution], async () => {
  selectedCell.value = null
  if (currentBounds.value) {
    await loadMap(currentBounds.value)
  }
})

async function loadMap(bounds = currentBounds.value) {
  if (!bounds) return
  loading.value = true
  error.value = null
  try {
    mapData.value = await fetchMap({
      region: selectedRegion.value,
      species: selectedSpecies.value,
      resolution: resolution.value,
      ...bounds,
    })
  } catch (e) {
    error.value = 'Erreur lors du chargement de la carte. Réessayez dans quelques secondes.'
    console.error(e)
  } finally {
    loading.value = false
  }
}

function onBoundsChange(bounds: { south: number; west: number; north: number; east: number }) {
  currentBounds.value = bounds
  if (pendingInitialLoad.value) {
    pendingInitialLoad.value = false
    void loadMap(bounds)
  }
}

async function refreshVisibleArea() {
  if (currentBounds.value) {
    await loadMap(currentBounds.value)
  }
}

function onCellSelect(cell: MapCell) {
  selectedCell.value = cell
}
</script>

<template>
  <div class="app-shell">
    <header class="topbar">
      <div>
        <h1>Forage Mapper</h1>
        <p>Chartreuse · Belledonne · Vercors — planification de sorties mycologiques</p>
      </div>
      <div class="controls">
        <label>
          Massif
          <select v-model="selectedRegion">
            <option v-for="region in regions" :key="region.id" :value="region.id">
              {{ region.name }}
            </option>
          </select>
        </label>
        <label>
          Espèce
          <select v-model="selectedSpecies">
            <option v-for="sp in speciesList" :key="sp.id" :value="sp.id">
              {{ sp.name }}
            </option>
          </select>
        </label>
        <label>
          Maille
          <select v-model.number="resolution">
            <option :value="250">250 m</option>
            <option :value="500">500 m</option>
            <option :value="750">750 m</option>
          </select>
        </label>
        <label>
          Affichage
          <select v-model="viewMode">
            <option value="grid">Quadrillage</option>
            <option value="circles">Cercles</option>
            <option value="heatmap">Heatmap</option>
          </select>
        </label>
        <button type="button" class="btn" :disabled="loading" @click="refreshVisibleArea">
          {{ loading ? 'Analyse…' : 'Analyser la zone visible' }}
        </button>
      </div>
    </header>

    <div v-if="error" class="error">{{ error }}</div>

    <main class="layout">
      <section class="map-wrap">
        <ForageMap
          v-if="currentRegion"
          :cells="mapData?.cells ?? []"
          :center="currentRegion.center"
          :view-mode="viewMode"
          :resolution="resolution"
          @cell-select="onCellSelect"
          @bounds-change="onBoundsChange"
        />
      </section>
      <InfoPanel :cell="selectedCell" :map-data="mapData" :loading="loading" />
    </main>
  </div>
</template>

<style>
:root {
  font-family: 'Segoe UI', system-ui, sans-serif;
  color: #2b261f;
  background: #ebe6dc;
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
.app-shell {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.topbar {
  background: #1f3d2b;
  color: #f4f1ea;
  padding: 0.85rem 1rem 1rem;
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  justify-content: space-between;
  align-items: flex-end;
}

.topbar h1 {
  margin: 0;
  font-size: 1.35rem;
}

.topbar p {
  margin: 0.2rem 0 0;
  font-size: 0.88rem;
  opacity: 0.85;
}

.controls {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  align-items: end;
}

label {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

select {
  min-width: 8rem;
  padding: 0.4rem 0.5rem;
  border-radius: 6px;
  border: none;
}

.btn {
  background: #4caf50;
  color: #fff;
  border: none;
  border-radius: 6px;
  padding: 0.55rem 0.85rem;
  font-weight: 600;
  cursor: pointer;
}

.btn:disabled {
  opacity: 0.6;
  cursor: wait;
}

.error {
  background: #fdecea;
  color: #a12622;
  padding: 0.6rem 1rem;
}

.layout {
  flex: 1;
  display: grid;
  grid-template-columns: 1fr min(360px, 38vw);
  min-height: 0;
}

.map-wrap {
  min-height: 420px;
  height: calc(100vh - 120px);
}

@media (max-width: 900px) {
  .layout {
    grid-template-columns: 1fr;
    grid-template-rows: 55vh auto;
  }

  .map-wrap {
    height: 55vh;
  }
}
</style>
