<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import type { AccessWalk, Bounds, LayerGrid } from '../types'
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
  accessWalk: AccessWalk | null
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
const accessLayer = shallowRef<L.LayerGroup | null>(null)
const userMarker = shallowRef<L.Marker | null>(null)
const accuracyCircle = shallowRef<L.Circle | null>(null)
const baseLayers = shallowRef<Record<string, L.TileLayer>>({})

let watchId: number | null = null
let hasCenteredOnUser = false
let locateButton: HTMLAnchorElement | null = null

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
  new LocateControl({ position: 'bottomright' }).addTo(instance)
  L.control.scale({ imperial: false, position: 'bottomleft' }).addTo(instance)

  Object.entries(BASEMAPS).forEach(([key, config]) => {
    baseLayers.value[key] = L.tileLayer(config.url, {
      attribution: config.attribution,
      maxZoom: config.maxZoom,
    })
  })
  baseLayers.value[props.basemap]?.addTo(instance)

  highlightLayer.value = L.layerGroup().addTo(instance)
  accessLayer.value = L.layerGroup().addTo(instance)

  instance.on('moveend', publishViewport)
  instance.on('click', (event: L.LeafletMouseEvent) => {
    emit('locationPicked', { lat: event.latlng.lat, lng: event.latlng.lng })
  })

  map.value = instance
  instance.whenReady(publishViewport)
  renderAccessWalk(props.accessWalk)
})

onBeforeUnmount(() => {
  stopLocate()
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

watch(
  () => props.accessWalk,
  (walk) => renderAccessWalk(walk),
)

function renderRaster(grid: LayerGrid | null) {
  const instance = map.value
  if (!instance) return

  if (rasterLayer.value) {
    instance.removeLayer(rasterLayer.value)
    rasterLayer.value = null
  }
  if (!grid || grid.values.length === 0) return

  const smoothed = grid.legend.categorical || grid.sparseNulls ? grid : fillHoles(grid)
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

  const thresholds = grid.legend.stops.filter((stop) => stop.label).slice(1).map((stop) => stop.value)
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
      title: `${area} ha · indice ${Math.round(sector.minScore)}–${Math.round(sector.maxScore)}${sector.accessMeters != null && sector.accessMeters < 9999 ? ` · chemin à ${sector.accessMeters} m` : ''}`,
    })

    marker
      .on('click', () => emit('locationPicked', { lat: sector.lat, lng: sector.lng }))
      .addTo(group)
  })
}

function renderAccessWalk(walk: AccessWalk | null) {
  const group = accessLayer.value
  if (!group) return

  group.clearLayers()
  if (!walk?.reachable || walk.coordinates.length < 2) return

  const latlngs = walk.coordinates.map((point) => L.latLng(point.lat, point.lng))
  const split = Math.max(0, Math.min(walk.approachFromIndex, latlngs.length - 1))
  const trail = latlngs.slice(0, split + 1)
  const approach = latlngs.slice(split)

  if (trail.length >= 2) {
    L.polyline(trail, {
      color: '#9a3412',
      weight: 4,
      opacity: 0.92,
      lineJoin: 'round',
    }).addTo(group)
  }
  if (approach.length >= 2) {
    L.polyline(approach, {
      color: '#c2410c',
      weight: 3,
      opacity: 0.9,
      dashArray: '7 7',
      lineJoin: 'round',
    }).addTo(group)
  }

  const start = walk.start ?? walk.coordinates[0]
  L.marker([start.lat, start.lng], {
    icon: L.divIcon({
      className: 'access-start',
      html: '<span></span>',
      iconSize: [12, 12],
      iconAnchor: [6, 6],
    }),
    interactive: false,
    keyboard: false,
  }).addTo(group)
}

const LocateControl = L.Control.extend({
  onAdd() {
    const bar = L.DomUtil.create('div', 'leaflet-bar leaflet-control')
    const button = L.DomUtil.create('a', 'myco-locate-btn', bar) as HTMLAnchorElement
    button.href = '#'
    button.role = 'button'
    button.title = 'Me localiser'
    button.setAttribute('aria-label', 'Me localiser')
    button.setAttribute('aria-pressed', 'false')
    button.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>'
    locateButton = button
    L.DomEvent.disableClickPropagation(bar)
    L.DomEvent.disableScrollPropagation(bar)
    L.DomEvent.on(button, 'click', (event) => {
      L.DomEvent.preventDefault(event)
      toggleLocate()
    })
    return bar
  },
  onRemove() {
    locateButton = null
  },
})

function setLocateStatus(message: string, active: boolean) {
  if (!locateButton) return
  locateButton.title = message
  locateButton.setAttribute('aria-label', message)
  locateButton.setAttribute('aria-pressed', active ? 'true' : 'false')
  locateButton.classList.toggle('is-active', active)
}

function stopLocate() {
  if (watchId != null) {
    navigator.geolocation.clearWatch(watchId)
    watchId = null
  }
  hasCenteredOnUser = false
  userMarker.value?.remove()
  userMarker.value = null
  accuracyCircle.value?.remove()
  accuracyCircle.value = null
  setLocateStatus('Me localiser', false)
}

function onUserPosition(position: GeolocationPosition) {
  const instance = map.value
  if (!instance) return

  const latlng = L.latLng(position.coords.latitude, position.coords.longitude)
  const accuracy = Math.max(position.coords.accuracy, 12)

  if (!accuracyCircle.value) {
    accuracyCircle.value = L.circle(latlng, {
      radius: accuracy,
      color: '#2563eb',
      weight: 1,
      opacity: 0.7,
      fillColor: '#3b82f6',
      fillOpacity: 0.15,
      interactive: false,
    }).addTo(instance)
  } else {
    accuracyCircle.value.setLatLng(latlng)
    accuracyCircle.value.setRadius(accuracy)
  }

  if (!userMarker.value) {
    userMarker.value = L.marker(latlng, {
      icon: L.divIcon({
        className: 'user-pin',
        html: '<span></span>',
        iconSize: [16, 16],
        iconAnchor: [8, 8],
      }),
      interactive: true,
      keyboard: false,
      zIndexOffset: 1200,
      title: 'Vous êtes ici',
    }).addTo(instance)
    userMarker.value.on('click', (event) => {
      L.DomEvent.stop(event)
    })
  } else {
    userMarker.value.setLatLng(latlng)
  }

  if (!hasCenteredOnUser) {
    hasCenteredOnUser = true
    instance.setView(latlng, Math.max(instance.getZoom(), 15))
  }

  setLocateStatus('Arrêter le suivi', true)
}

function onLocateError(error: GeolocationPositionError) {
  stopLocate()
  const message = error.code === error.PERMISSION_DENIED
    ? 'Géolocalisation refusée'
    : error.code === error.TIMEOUT
      ? 'Géolocalisation hors délai'
      : 'Géolocalisation indisponible'
  setLocateStatus(message, false)
}

function toggleLocate() {
  if (watchId != null) {
    stopLocate()
    return
  }
  if (!navigator.geolocation) {
    setLocateStatus('Géolocalisation indisponible', false)
    return
  }
  setLocateStatus('Recherche de la position…', true)
  watchId = navigator.geolocation.watchPosition(onUserPosition, onLocateError, {
    enableHighAccuracy: true,
    timeout: 15_000,
    maximumAge: 5_000,
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

.access-start span {
  display: block;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  border: 2px solid #f4e9c8;
  background: #9a3412;
  box-shadow: 0 0 0 2px rgb(0 0 0 / 35%);
}

.user-pin span {
  display: block;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  border: 3px solid #f4e9c8;
  background: #2563eb;
  box-shadow: 0 0 0 2px rgb(0 0 0 / 35%);
}

.myco-locate-btn {
  display: flex !important;
  align-items: center;
  justify-content: center;
}

.myco-locate-btn.is-active {
  background: #2563eb;
  color: #fff;
}
</style>
