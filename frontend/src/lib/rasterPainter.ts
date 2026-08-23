import type { LayerGrid } from '../types'
import { createColorScale } from './colorScale'

/**
 * Paints the value grid into an RGBA image, one pixel per cell. Leaflet then scales it
 * over the map, and the browser's bilinear filtering turns the 100 m lattice into a
 * continuous surface — no visible squares.
 */
export function paintLayer(grid: LayerGrid, opacity: number): string {
  const canvas = document.createElement('canvas')
  canvas.width = grid.columns
  canvas.height = grid.rows

  const context = canvas.getContext('2d')
  if (!context) return ''

  const image = context.createImageData(grid.columns, grid.rows)
  const scale = createColorScale(grid.legend)
  const alpha = Math.round(Math.max(0, Math.min(1, opacity)) * 255)

  for (let i = 0; i < grid.values.length; i++) {
    const value = grid.values[i]
    const offset = i * 4

    if (value === null || value === undefined) {
      image.data[offset + 3] = 0
      continue
    }

    const { r, g, b } = scale(value)
    image.data[offset] = r
    image.data[offset + 1] = g
    image.data[offset + 2] = b
    image.data[offset + 3] = alpha
  }

  context.putImageData(image, 0, 0)

  return canvas.toDataURL('image/png')
}

/**
 * Fills the gaps left by cells outside the precomputed area so bilinear scaling does
 * not bleed transparent pixels into the coloured surface.
 */
export function fillHoles(grid: LayerGrid): LayerGrid {
  const values = [...grid.values]
  const { columns, rows } = grid

  for (let row = 0; row < rows; row++) {
    for (let column = 0; column < columns; column++) {
      const index = row * columns + column
      if (values[index] !== null && values[index] !== undefined) continue

      let sum = 0
      let count = 0
      for (let dy = -1; dy <= 1; dy++) {
        for (let dx = -1; dx <= 1; dx++) {
          const ny = row + dy
          const nx = column + dx
          if (ny < 0 || nx < 0 || ny >= rows || nx >= columns) continue
          const neighbour = grid.values[ny * columns + nx]
          if (neighbour === null || neighbour === undefined) continue
          sum += neighbour
          count++
        }
      }

      if (count >= 5) {
        values[index] = sum / count
      }
    }
  }

  return { ...grid, values }
}
