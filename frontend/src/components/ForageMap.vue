<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import 'leaflet.heat'
import type { MapCell, MapViewMode } from '../types'
import { scoreColor } from '../utils/colors'

const props = defineProps<{
  cells: MapCell[]
  center: { lat: number; lng: number }
  viewMode: MapViewMode
  resolution: number
}>()

const emit = defineEmits<{
  cellSelect: [cell: MapCell]
  boundsChange: [bounds: { south: number; west: number; north: number; east: number }]
}>()

const mapRoot = ref<HTMLElement | null>(null)
let map: L.Map | null = null
let layerGroup: L.LayerGroup | null = null
let heatLayer: L.Layer | null = null

onMounted(() => {
  if (!mapRoot.value) return

  map = L.map(mapRoot.value, { zoomControl: false }).setView([props.center.lat, props.center.lng], 11)
  L.control.zoom({ position: 'bottomright' }).addTo(map)

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap | IGN contexte montagne',
    maxZoom: 17,
  }).addTo(map)

  layerGroup = L.layerGroup().addTo(map)
  map.on('moveend', emitBounds)

  renderLayers()
})

watch(
  () => [props.cells, props.viewMode, props.resolution],
  () => renderLayers(),
  { deep: true },
)

watch(
  () => props.center,
  (center) => {
    if (map) map.setView([center.lat, center.lng], map.getZoom())
  },
  { deep: true },
)

function emitBounds() {
  if (!map) return
  const b = map.getBounds()
  emit('boundsChange', {
    south: b.getSouth(),
    west: b.getWest(),
    north: b.getNorth(),
    east: b.getEast(),
  })
}

function renderLayers() {
  if (!map || !layerGroup) return

  layerGroup.clearLayers()
  if (heatLayer) {
    map.removeLayer(heatLayer)
    heatLayer = null
  }

  if (props.cells.length === 0) return

  if (props.viewMode === 'heatmap') {
    const points = props.cells.map((c) => [c.lat, c.lng, c.score / 100] as [number, number, number])
    // @ts-expect-error leaflet.heat plugin
    heatLayer = L.heatLayer(points, { radius: 28, blur: 18, maxZoom: 14, minOpacity: 0.35 }).addTo(map)
    return
  }

  const radius = Math.max(props.resolution / 2, 120)

  props.cells.forEach((cell) => {
    if (props.viewMode === 'circles') {
      L.circle([cell.lat, cell.lng], {
        radius,
        color: scoreColor(cell.score),
        fillColor: scoreColor(cell.score),
        fillOpacity: 0.55,
        weight: 1,
      })
        .on('click', () => emit('cellSelect', cell))
        .bindTooltip(`${cell.score.toFixed(0)} pts`, { direction: 'top' })
        .addTo(layerGroup!)
      return
    }

    const halfLat = (props.resolution / 111320) / 2
    const halfLng = (props.resolution / (111320 * Math.cos((cell.lat * Math.PI) / 180))) / 2
    const bounds: L.LatLngBoundsExpression = [
      [cell.lat - halfLat, cell.lng - halfLng],
      [cell.lat + halfLat, cell.lng + halfLng],
    ]

    L.rectangle(bounds, {
      color: scoreColor(cell.score),
      fillColor: scoreColor(cell.score),
      fillOpacity: 0.5,
      weight: 1,
    })
      .on('click', () => emit('cellSelect', cell))
      .addTo(layerGroup!)
  })
}
</script>

<template>
  <div ref="mapRoot" class="map-root" />
</template>

<style scoped>
.map-root {
  width: 100%;
  height: 100%;
  min-height: 420px;
}
</style>
