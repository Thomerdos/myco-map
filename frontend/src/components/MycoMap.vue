<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import type { Bounds, Highlight, LayerGrid } from '../types'
import { fillHoles, paintLayer } from '../lib/rasterPainter'
import { buildContours } from '../lib/contours'

const props = defineProps<{
  grid: LayerGrid | null
  center: { lat: number; lng: number }
  zoom: number
  opacity: number
  showContours: boolean
  basemap: string
  selected: { lat: number; lng: number } | null
}>()

const emit = defineEmits<{
  viewportChanged: [bounds: Bounds]
  locationPicked: [point: { lat: number; lng: number }]
}>()

const mapElement = ref<HTMLElement | null>(null)
const map = shallowRef<L.Map | null>(null)
const rasterLayer = shallowRef<L.ImageOverlay | null>(null)
const contourLayer = shallowRef<L.GeoJSON | null>(null)
const highlightLayer = shallowRef<L.LayerGroup | null>(null)
const selectionMarker = shallowRef<L.Marker | null>(null)
const baseLayers = shallowRef<Record<string, L.TileLayer>>({})

const BASEMAPS: Record<string, { url: string; attribution: string; maxZoom: number }> = {
  plan: {
    url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    attribution: '&copy; OpenStreetMap',
    maxZoom: 18,
  },
  topo: {
    url: 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
    attribution: '&copy; OpenTopoMap, &copy; OpenStreetMap',
    maxZoom: 17,
  },
  satellite: {
    url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    attribution: 'Imagery &copy; Esri',
    maxZoom: 18,
  },
}

onMounted(() => {
  if (!mapElement.value) return

  const instance = L.map(mapElement.value, {
    zoomControl: false,
    preferCanvas: true,
  }).setView([props.center.lat, props.center.lng], props.zoom)

  L.control.zoom({ position: 'bottomright' }).addTo(instance)
  L.control.scale({ imperial: false, position: 'bottomleft' }).addTo(instance)

  Object.entries(BASEMAPS).forEach(([key, config]) => {
    baseLayers.value[key] = L.tileLayer(config.url, {
      attribution: config.attribution,
      maxZoom: config.maxZoom,
    })
  })
  baseLayers.value[props.basemap]?.addTo(instance)

  highlightLayer.value = L.layerGroup().addTo(instance)

  instance.on('moveend', publishViewport)
  instance.on('click', (event: L.LeafletMouseEvent) => {
    emit('locationPicked', { lat: event.latlng.lat, lng: event.latlng.lng })
  })

  map.value = instance
  instance.whenReady(publishViewport)
})

onBeforeUnmount(() => {
  map.value?.remove()
  map.value = null
})

function publishViewport() {
  const instance = map.value
  if (!instance) return

  const bounds = instance.getBounds()
  emit('viewportChanged', {
    south: bounds.getSouth(),
    west: bounds.getWest(),
    north: bounds.getNorth(),
    east: bounds.getEast(),
  })
}

watch(
  () => props.basemap,
  (next, previous) => {
    const instance = map.value
    if (!instance) return
    if (previous && baseLayers.value[previous]) {
      instance.removeLayer(baseLayers.value[previous])
    }
    baseLayers.value[next]?.addTo(instance)
    baseLayers.value[next]?.bringToBack()
  },
)

watch(
  () => props.opacity,
  (value) => rasterLayer.value?.setOpacity(value),
)

watch(
  () => props.grid,
  (grid) => {
    renderRaster(grid)
    renderContours(grid)
    renderHighlights(grid?.highlights ?? [])
  },
)

watch(
  () => props.showContours,
  () => renderContours(props.grid),
)

watch(
  () => props.selected,
  (point) => {
    const instance = map.value
    if (!instance) return

    if (selectionMarker.value) {
      instance.removeLayer(selectionMarker.value)
      selectionMarker.value = null
    }
    if (!point) return

    selectionMarker.value = L.marker([point.lat, point.lng], {
      icon: L.divIcon({
        className: 'selection-pin',
        html: '<span></span>',
        iconSize: [18, 18],
        iconAnchor: [9, 9],
      }),
    }).addTo(instance)
  },
)

function renderRaster(grid: LayerGrid | null) {
  const instance = map.value
  if (!instance) return

  if (rasterLayer.value) {
    instance.removeLayer(rasterLayer.value)
    rasterLayer.value = null
  }
  if (!grid || grid.values.length === 0) return

  const smoothed = grid.legend.categorical ? grid : fillHoles(grid)
  const dataUrl = paintLayer(smoothed, 1)
  if (!dataUrl) return

  const bounds = L.latLngBounds(
    [grid.bounds.south, grid.bounds.west],
    [grid.bounds.north, grid.bounds.east],
  )

  rasterLayer.value = L.imageOverlay(dataUrl, bounds, {
    opacity: props.opacity,
    interactive: false,
    className: grid.legend.categorical ? 'raster-crisp' : 'raster-smooth',
  }).addTo(instance)
}

function renderContours(grid: LayerGrid | null) {
  const instance = map.value
  if (!instance) return

  if (contourLayer.value) {
    instance.removeLayer(contourLayer.value)
    contourLayer.value = null
  }
  if (!grid || !props.showContours || grid.legend.categorical) return

  const thresholds = grid.legend.stops.slice(1).map((stop) => stop.value)
  const features = buildContours(fillHoles(grid), thresholds)

  contourLayer.value = L.geoJSON(features, {
    style: () => ({
      color: '#12211a',
      weight: 0.9,
      opacity: 0.5,
      fill: false,
    }),
    interactive: false,
  }).addTo(instance)
}

function renderHighlights(highlights: Highlight[]) {
  const group = highlightLayer.value
  if (!group) return

  group.clearLayers()

  highlights.forEach((highlight, index) => {
    L.marker([highlight.lat, highlight.lng], {
      icon: L.divIcon({
        className: 'highlight-pin',
        html: `<span>${index + 1}</span>`,
        iconSize: [24, 24],
        iconAnchor: [12, 12],
      }),
      title: `${highlight.score} / 100 — ${highlight.levelLabel}`,
    })
      .on('click', () => emit('locationPicked', { lat: highlight.lat, lng: highlight.lng }))
      .addTo(group)
  })
}
</script>

<template>
  <div ref="mapElement" class="map-surface" />
</template>

<style>
.map-surface {
  width: 100%;
  height: 100%;
}

.raster-smooth {
  image-rendering: auto;
}

.raster-crisp {
  image-rendering: pixelated;
}

.highlight-pin span {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: #f6f3ea;
  border: 2px solid #14342a;
  color: #14342a;
  font-size: 0.72rem;
  font-weight: 700;
  box-shadow: 0 1px 4px rgb(0 0 0 / 45%);
}

.selection-pin span {
  display: block;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  border: 3px solid #f4e9c8;
  background: #c2410c;
  box-shadow: 0 0 0 2px rgb(0 0 0 / 35%);
}
</style>
