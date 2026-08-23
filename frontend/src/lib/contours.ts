import { contours } from 'd3-contour'
import type { Feature, MultiPolygon } from 'geojson'
import type { LayerGrid } from '../types'

/**
 * Isolines of the value surface, converted from grid space back to lat/lng so Leaflet
 * can draw them as crisp zone boundaries on top of the raster.
 */
export function buildContours(grid: LayerGrid, thresholds: number[]): Feature<MultiPolygon>[] {
  const values = grid.values.map((value) => (value === null || value === undefined ? -1 : value))

  const generator = contours().size([grid.columns, grid.rows]).thresholds(thresholds)
  const latitudeSpan = grid.bounds.north - grid.bounds.south
  const longitudeSpan = grid.bounds.east - grid.bounds.west

  return generator(values).map((polygon) => ({
    type: 'Feature',
    properties: { threshold: polygon.value },
    geometry: {
      type: 'MultiPolygon',
      coordinates: polygon.coordinates.map((rings) =>
        rings.map((ring) =>
          ring.map(([x, y]) => [
            grid.bounds.west + (x / grid.columns) * longitudeSpan,
            grid.bounds.north - (y / grid.rows) * latitudeSpan,
          ]),
        ),
      ),
    },
  }))
}
