<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import type { Bounds, LayerGrid } from '../types'
import { fillHoles, paintLayer } from '../lib/rasterPainter'
import { buildContours } from '../lib/contours'

const props = defineProps<{
  grid: LayerGrid | null
  center: { lat: number; lng: number }
  zoom: number
  opacity: number
  showContours: boolean
  showSpots: boolean
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
    renderHighlights(grid)
  },
)

watch(
  () => props.showContours,
  () => renderContours(props.grid),
)

watch(
  () => props.showSpots,
  () => renderHighlights(props.grid),
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

/**
 * Outlines of connected patches ≥ 90, plus an area label at the centroid.
 * Numbered pins were dropped: the score surface is a plateau, not a ranking.
 */
function renderHighlights(grid: LayerGrid | null) {
  const group = highlightLayer.value
  if (!group) return

  group.clearLayers()
  if (!grid || !props.showSpots || grid.legend.categorical) return

  const threshold = grid.statistics.hotspotThreshold ?? 90
  if (grid.layer === 'potential') {
    const features = buildContours(fillHoles(grid), [threshold])
    L.geoJSON(features, {
      style: () => ({
        color: '#c2410c',
        weight: 1.6,
        opacity: 0.9,
        fillColor: '#ef8b3c',
        fillOpacity: 0.12,
      }),
      interactive: false,
    }).addTo(group)
  }

  grid.sectors?.forEach((sector) => {
    const area = sector.areaHa < 10 ? sector.areaHa.toFixed(1) : String(Math.round(sector.areaHa))
    const marker = L.marker([sector.lat, sector.lng], {
      icon: L.divIcon({
        className: 'sector-chip',
        html: `<span>${area}&nbsp;ha</span>`,
        iconSize: [56, 18],
        iconAnchor: [28, 9],
      }),
      title: `${area} ha · indice ${Math.round(sector.minScore)}–${Math.round(sector.maxScore)}`,
    })

    marker
      .on('click', () => emit('locationPicked', { lat: sector.lat, lng: sector.lng }))
      .addTo(group)
  })
}
</script>

<template>
  <div ref="mapElement" class="map-surface" />
</template>

<style>
.map-surface,
.leaflet-container {
  width: 100%;
  height: 100%;
  z-index: 0;
}

.raster-smooth {
  image-rendering: auto;
}

.raster-crisp {
  image-rendering: pixelated;
}

.sector-chip span {
  display: block;
  padding: 1px 6px;
  border: 1px solid #16190f;
  border-radius: 5px;
  background: rgb(248 245 236 / 94%);
  color: #22261a;
  font-size: 0.68rem;
  font-variant-numeric: tabular-nums;
  font-weight: 700;
  white-space: nowrap;
  box-shadow: 0 1px 3px rgb(0 0 0 / 35%);
  text-align: center;
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
