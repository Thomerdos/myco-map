import type { LayerGrid, Sector } from '../types'
import { fillHoles, paintLayer } from './rasterPainter'
import { zipStore } from './zipStore'

function escapeXml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&apos;')
}

function dataUrlToBytes(dataUrl: string): Uint8Array {
  const comma = dataUrl.indexOf(',')
  const binary = atob(comma >= 0 ? dataUrl.slice(comma + 1) : dataUrl)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i)
  }
  return bytes
}

function documentTitle(grid: LayerGrid): string {
  const species = grid.species?.name ?? 'Myco Map'
  const layer = grid.legend.title || grid.layerLabel
  if (grid.scoringMode === 'habitat') {
    return `${species} — ${layer} (habitat)`
  }
  if (grid.asOfDate) {
    const date = new Date(`${grid.asOfDate}T12:00:00`)
    const label = date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
    return `${species} — ${layer} (${label})`
  }
  return `${species} — ${layer}`
}

export function exportBasename(grid: LayerGrid): string {
  const species = grid.species?.id ?? 'carte'
  const stamp = grid.scoringMode === 'habitat'
    ? 'habitat'
    : (grid.asOfDate ?? 'vue')
  return `myco-${species}-${stamp}`
}

export function hasExportableSectors(grid: LayerGrid): boolean {
  return grid.sectors.length > 0
}

function rankedSectors(grid: LayerGrid): Sector[] {
  return [...grid.sectors].sort((left, right) => right.maxScore - left.maxScore)
}

function sectorScoreLabel(sector: Sector): string {
  return String(Math.round(sector.maxScore))
}

function iconScale(score: number): string {
  if (score >= 96) return '1.40'
  if (score >= 93) return '1.25'
  if (score >= 90) return '1.15'
  return '0.95'
}

function overlayPng(grid: LayerGrid, opacity: number): Uint8Array | null {
  if (grid.values.length === 0) return null
  const smoothed = grid.legend.categorical || grid.sparseNulls ? grid : fillHoles(grid)
  const dataUrl = paintLayer(smoothed, opacity)
  if (!dataUrl) return null
  return dataUrlToBytes(dataUrl)
}

function buildKml(grid: LayerGrid): string {
  const title = escapeXml(documentTitle(grid))
  const { north, south, east, west } = grid.bounds
  const placemarks = rankedSectors(grid).map((sector) => {
    const score = Math.round(sector.maxScore)
    return `
    <Placemark>
      <name>${score}</name>
      <Style>
        <IconStyle>
          <color>ffd326c0</color>
          <scale>${iconScale(score)}</scale>
        </IconStyle>
        <LabelStyle>
          <color>ffd326c0</color>
          <scale>1.2</scale>
        </LabelStyle>
      </Style>
      <Point>
        <coordinates>${sector.lng.toFixed(6)},${sector.lat.toFixed(6)},0</coordinates>
      </Point>
    </Placemark>`
  }).join('')

  return `<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2">
  <Document>
    <name>${title}</name>
    <GroundOverlay>
      <name>${escapeXml(grid.legend.title || grid.layerLabel)}</name>
      <drawOrder>1</drawOrder>
      <Icon>
        <href>overlay.png</href>
      </Icon>
      <LatLonBox>
        <north>${north}</north>
        <south>${south}</south>
        <east>${east}</east>
        <west>${west}</west>
        <rotation>0</rotation>
      </LatLonBox>
    </GroundOverlay>
    ${placemarks}
  </Document>
</kml>
`
}

export function buildKmz(grid: LayerGrid, opacity: number): Blob | null {
  const png = overlayPng(grid, opacity)
  if (!png) return null
  const kml = new TextEncoder().encode(buildKml(grid))
  const bytes = zipStore([
    { name: 'doc.kml', data: kml },
    { name: 'overlay.png', data: png },
  ])
  const copy = new ArrayBuffer(bytes.byteLength)
  new Uint8Array(copy).set(bytes)
  return new Blob([copy], { type: 'application/vnd.google-earth.kmz' })
}

export function buildSectorsGpx(grid: LayerGrid): Blob | null {
  if (grid.sectors.length === 0) return null
  const title = escapeXml(documentTitle(grid))
  const waypoints = rankedSectors(grid).map((sector) => `
  <wpt lat="${sector.lat.toFixed(6)}" lon="${sector.lng.toFixed(6)}">
    <name>${escapeXml(sectorScoreLabel(sector))}</name>
  </wpt>`).join('')

  const gpx = `<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="myco-map" xmlns="http://www.topografix.com/GPX/1/1">
  <metadata>
    <name>${title}</name>
  </metadata>
${waypoints}
</gpx>
`
  return new Blob([gpx], { type: 'application/gpx+xml' })
}

export function downloadBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.rel = 'noopener'
  document.body.append(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
