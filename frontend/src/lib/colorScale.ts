import type { Legend } from '../types'

interface Rgb {
  r: number
  g: number
  b: number
}

function hexToRgb(hex: string): Rgb {
  const clean = hex.replace('#', '')
  return {
    r: parseInt(clean.slice(0, 2), 16),
    g: parseInt(clean.slice(2, 4), 16),
    b: parseInt(clean.slice(4, 6), 16),
  }
}

/**
 * Builds a lookup from a legend: continuous legends interpolate between stops,
 * categorical legends match the exact stored code.
 */
export function createColorScale(legend: Legend): (value: number) => Rgb {
  const stops = legend.stops.map((stop) => ({ value: stop.value, rgb: hexToRgb(stop.color) }))

  if (legend.categorical) {
    const byValue = new Map(stops.map((stop) => [stop.value, stop.rgb]))
    const fallback = stops[0]?.rgb ?? { r: 0, g: 0, b: 0 }
    return (value: number) => byValue.get(Math.round(value)) ?? fallback
  }

  return (value: number) => {
    if (value <= stops[0].value) return stops[0].rgb
    const last = stops[stops.length - 1]
    if (value >= last.value) return last.rgb

    for (let i = 0; i < stops.length - 1; i++) {
      const from = stops[i]
      const to = stops[i + 1]
      if (value >= from.value && value <= to.value) {
        const ratio = (value - from.value) / (to.value - from.value || 1)
        return {
          r: Math.round(from.rgb.r + (to.rgb.r - from.rgb.r) * ratio),
          g: Math.round(from.rgb.g + (to.rgb.g - from.rgb.g) * ratio),
          b: Math.round(from.rgb.b + (to.rgb.b - from.rgb.b) * ratio),
        }
      }
    }

    return last.rgb
  }
}

export function legendGradient(legend: Legend): string {
  if (legend.categorical) return ''
  const min = legend.stops[0].value
  const max = legend.stops[legend.stops.length - 1].value
  const span = max - min || 1

  const parts = legend.stops.map(
    (stop) => `${stop.color} ${Math.round(((stop.value - min) / span) * 100)}%`,
  )

  return `linear-gradient(to right, ${parts.join(', ')})`
}
