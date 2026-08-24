<script setup lang="ts">
import { Button, Dialog } from '@vuetify/v0'
import { useDebounceFn, useMediaQuery } from '@vueuse/core'
import { computed, onMounted, ref, watch } from 'vue'
import ControlPanel from './components/ControlPanel.vue'
import DetailPanel from './components/DetailPanel.vue'
import MycoMap from './components/MycoMap.vue'
import TopBar from './components/TopBar.vue'
import { fetchContext, fetchLayer, fetchLocation, fetchProjection } from './lib/api'
import type { Bounds, LayerGrid, LocationReport, MapContext, ScoreProjection } from './types'

const context = ref<MapContext | null>(null)
const grid = ref<LayerGrid | null>(null)
const report = ref<LocationReport | null>(null)
const projection = ref<ScoreProjection | null>(null)

const activeLayer = ref('potential')
const activeSpecies = ref('cepe')
const habitatOnly = ref(false)
const projectionDate = ref('')
const opacity = ref(0.72)
const showContours = ref(false)
const showSpots = ref(true)
const basemap = ref('topo')
const selectedPoint = ref<{ lat: number; lng: number } | null>(null)
const detailOpen = ref(false)
const controlsOpen = ref(false)

const isMobile = useMediaQuery('(max-width: 720px)')

const loadingLayer = ref(false)
const loadingReport = ref(false)
const loadingProjection = ref(false)
const error = ref<string | null>(null)
const notReady = ref(false)

let viewport: Bounds | null = null
let requestToken = 0
let projectionToken = 0

const mapCenter = computed(() => context.value?.area.center ?? { lat: 45.19, lng: 5.79 })
const mapZoom = computed(() => context.value?.area.zoom ?? 11)
const scoringMode = computed(() => (habitatOnly.value ? 'habitat' : 'moment'))
const weatherChip = computed(() => (habitatOnly.value ? null : (grid.value?.weather ?? null)))
const activeSpeciesMeta = computed(
  () => context.value?.species.find((item) => item.id === activeSpecies.value) ?? null,
)
const layerOptions = computed(() =>
  (context.value?.layers ?? []).map((layer) => ({
    ...layer,
    label: habitatOnly.value && layer.id === 'potential' ? "Potentiel d'habitat" : layer.label,
  })),
)
const projectionFrom = computed(() => context.value?.projection.from ?? '')
const projectionTo = computed(() => context.value?.projection.to ?? '')

watch(
  isMobile,
  (mobile) => {
    controlsOpen.value = !mobile
    if (!mobile) return
    // Mobile sheets start closed unless a point is already selected.
    if (!selectedPoint.value) detailOpen.value = false
  },
  { immediate: true },
)

onMounted(async () => {
  try {
    const loaded = await fetchContext()
    context.value = loaded
    notReady.value = !loaded.ready
    projectionDate.value = loaded.projection.from
    if (loaded.ready && viewport) {
      await loadLayer(viewport)
      await loadProjection(viewport)
    }
  } catch (cause) {
    error.value = "Impossible de joindre l'API. Vérifiez que le backend tourne sur le port 8765."
    console.error(cause)
  }
})

async function loadLayer(bounds: Bounds) {
  const token = ++requestToken
  loadingLayer.value = true
  error.value = null

  try {
    const result = await fetchLayer({
      layer: activeLayer.value,
      species: activeSpecies.value,
      bounds,
      maxCells: 70000,
      mode: scoringMode.value,
      date: habitatOnly.value ? undefined : projectionDate.value || undefined,
    })
    if (token === requestToken) grid.value = result
  } catch (cause) {
    if (token === requestToken) {
      error.value = cause instanceof Error ? cause.message : 'Erreur de chargement du masque.'
    }
  } finally {
    if (token === requestToken) loadingLayer.value = false
  }
}

async function loadProjection(bounds: Bounds) {
  if (habitatOnly.value) {
    projection.value = null
    return
  }

  const token = ++projectionToken
  loadingProjection.value = true

  try {
    const result = await fetchProjection({
      species: activeSpecies.value,
      bounds,
    })
    if (token === projectionToken) projection.value = result
  } catch (cause) {
    if (token === projectionToken) {
      projection.value = null
      console.error(cause)
    }
  } finally {
    if (token === projectionToken) loadingProjection.value = false
  }
}

const scheduleLayer = useDebounceFn(() => {
  if (viewport && context.value?.ready) void loadLayer(viewport)
}, 280)

const scheduleProjection = useDebounceFn(() => {
  if (viewport && context.value?.ready && !habitatOnly.value) void loadProjection(viewport)
}, 600)

function onViewportChanged(bounds: Bounds) {
  viewport = bounds
  scheduleLayer()
  scheduleProjection()
}

async function onLocationPicked(point: { lat: number; lng: number }) {
  selectedPoint.value = point
  if (isMobile.value) controlsOpen.value = false
  detailOpen.value = true
  loadingReport.value = true

  try {
    report.value = await fetchLocation({
      lat: point.lat,
      lng: point.lng,
      species: activeSpecies.value,
      mode: scoringMode.value,
      date: habitatOnly.value ? undefined : projectionDate.value || undefined,
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
  if (viewport) {
    void loadLayer(viewport)
    void loadProjection(viewport)
  }
  if (selectedPoint.value) void onLocationPicked(selectedPoint.value)
}

function onHabitatOnlyChange(value: boolean) {
  habitatOnly.value = value
  if (value) projection.value = null
  else if (viewport) void loadProjection(viewport)
  if (viewport) void loadLayer(viewport)
  if (selectedPoint.value) void onLocationPicked(selectedPoint.value)
}

function onProjectionDateChange(date: string) {
  if (!date || date === projectionDate.value) return
  projectionDate.value = date
  if (viewport) void loadLayer(viewport)
  if (selectedPoint.value) void onLocationPicked(selectedPoint.value)
}

const controlPanelProps = computed(() => ({
  layers: layerOptions.value,
  species: context.value?.species ?? [],
  activeLayer: activeLayer.value,
  activeSpecies: activeSpecies.value,
  habitatOnly: habitatOnly.value,
  projectionDate: projectionDate.value,
  projectionFrom: projectionFrom.value,
  projectionTo: projectionTo.value,
  projection: projection.value,
  opacity: opacity.value,
  showContours: showContours.value,
  showSpots: showSpots.value,
  basemap: basemap.value,
  grid: grid.value,
  loading: loadingLayer.value,
}))
</script>

<template>
  <div class="relative h-screen overflow-hidden font-sans text-on-surface">
    <MycoMap
      class="absolute inset-0"
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

    <div class="ui-layer pointer-events-none absolute inset-0 flex flex-col gap-1.5 p-2 md:p-3">
      <TopBar
        :area-name="context?.area.name ?? 'Grenoble'"
        :habitat-only="habitatOnly"
        :loading-layer="loadingLayer"
        :loading-projection="loadingProjection"
        :is-mobile="isMobile"
        :controls-open="controlsOpen"
        :weather-label="weatherChip?.label ?? null"
        :in-season="grid?.species && !habitatOnly ? grid.species.inSeason : null"
        :projection="projection"
        :projection-date="projectionDate"
        @update:projection-date="onProjectionDateChange"
        @toggle-controls="controlsOpen = !controlsOpen"
      />

      <div class="relative min-h-0 flex-1">
        <aside
          v-if="!isMobile"
          class="pointer-events-auto absolute top-0 left-0 max-h-full w-[min(300px,calc(100vw-1.5rem))] overflow-auto rounded-[14px] border border-[#d9d0bc] shadow-xl"
        >
          <ControlPanel
            v-bind="controlPanelProps"
            @update:active-layer="onLayerChange"
            @update:active-species="onSpeciesChange"
            @update:habitat-only="onHabitatOnlyChange"
            @update:projection-date="onProjectionDateChange"
            @update:opacity="opacity = $event"
            @update:show-contours="showContours = $event"
            @update:show-spots="showSpots = $event"
            @update:basemap="basemap = $event"
          />
        </aside>

        <div v-if="grid && !grid.legend.categorical" class="pointer-events-auto absolute bottom-2 left-0 w-[min(240px,calc(100vw-1.5rem))] rounded-[10px] border border-[#d9d0bc] bg-surface/92 px-3 py-2">
          <span class="mb-1 block text-[0.7rem] uppercase tracking-wider text-secondary">{{ grid.legend.title }}</span>
          <div class="h-2.5 rounded-md border border-[#cfc6b1]" :style="{ background: `linear-gradient(to right, ${grid.legend.stops.map((stop) => stop.color).join(', ')})` }" />
          <div class="mt-1 flex justify-between text-[0.68rem] text-secondary">
            <span>{{ grid.legend.stops[0]?.label }}</span>
            <span>{{ grid.legend.stops[grid.legend.stops.length - 1]?.label }}</span>
          </div>
        </div>

        <p v-if="activeSpeciesMeta && !isMobile" class="pointer-events-none absolute right-0 bottom-2 m-0 max-w-[min(320px,calc(100vw-2rem))] rounded-[10px] bg-primary/90 px-3 py-2 text-xs leading-snug text-on-primary">
          {{ activeSpeciesMeta.summary }}
        </p>
      </div>
    </div>

    <aside
      v-if="!isMobile"
      class="ui-layer absolute top-0 right-0 h-full w-[min(360px,100vw)] bg-surface shadow-[-8px_0_28px_rgb(0_0_0_/_18%)] transition-transform duration-200"
      :class="detailOpen ? 'translate-x-0' : 'translate-x-full'"
    >
      <Button.Root
        class="absolute top-2 right-2 z-10 rounded-md border border-[#cfc6b1] bg-[#fffdf8] px-2 py-1 text-xs"
        @click="detailOpen = false"
      >
        <Button.Content>Fermer</Button.Content>
      </Button.Root>
      <div class="h-full overflow-y-auto pt-8">
        <DetailPanel
          :report="report"
          :sectors="grid?.sectors ?? []"
          :habitat-only="habitatOnly"
          :loading="loadingReport"
          @highlight-picked="onLocationPicked"
        />
      </div>
    </aside>

    <p v-if="error" class="ui-layer absolute top-3 left-1/2 m-0 max-w-[min(480px,calc(100vw-1.5rem))] -translate-x-1/2 rounded-lg bg-[#f8e0dc] px-4 py-2 text-sm text-error">
      {{ error }}
    </p>
    <p v-else-if="notReady" class="ui-layer absolute top-3 left-1/2 m-0 max-w-[min(480px,calc(100vw-1.5rem))] -translate-x-1/2 rounded-lg bg-[#f6ecd2] px-4 py-2 text-sm text-[#7a5312]">
      Données non précalculées — lancez <code>./dev.sh restore-data</code>.
    </p>

    <Dialog.Root v-if="isMobile" v-model="controlsOpen">
      <Dialog.Content class="sheet-dialog overflow-y-auto">
        <Dialog.Title class="sr-only">Filtres</Dialog.Title>
        <ControlPanel
          v-bind="controlPanelProps"
          @update:active-layer="onLayerChange"
          @update:active-species="onSpeciesChange"
          @update:habitat-only="onHabitatOnlyChange"
          @update:projection-date="onProjectionDateChange"
          @update:opacity="opacity = $event"
          @update:show-contours="showContours = $event"
          @update:show-spots="showSpots = $event"
          @update:basemap="basemap = $event"
        />
      </Dialog.Content>
    </Dialog.Root>

    <Dialog.Root v-if="isMobile" v-model="detailOpen">
      <Dialog.Content class="sheet-dialog overflow-y-auto">
        <div class="flex items-center justify-between px-4 pt-3">
          <Dialog.Title class="text-sm font-semibold">Détail du point</Dialog.Title>
          <Dialog.Close class="rounded-md border border-[#cfc6b1] bg-[#fffdf8] px-2 py-1 text-xs">Fermer</Dialog.Close>
        </div>
        <DetailPanel
          :report="report"
          :sectors="grid?.sectors ?? []"
          :habitat-only="habitatOnly"
          :loading="loadingReport"
          @highlight-picked="onLocationPicked"
        />
      </Dialog.Content>
    </Dialog.Root>
  </div>
</template>

<style scoped>
.ui-layer {
  z-index: 1100;
}
</style>
