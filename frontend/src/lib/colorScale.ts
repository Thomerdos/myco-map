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

const FADE_FLOOR = 0.04

/**
 * Opacity for the potential mask: soft yellow–red wash 70–90, then a frank cliff
 * at 90 so violet/fuchsia excellence patches dominate.
 */
export function createOpacityRamp(legend: Legend): (value: number) => number {
  const tip = legend.stops[legend.stops.length - 1]?.value ?? 100

  return (value: number) => {
    if (value < 70) {
      const t = Math.max(0, Math.min(1, value / 70))
      return FADE_FLOOR + 0.12 * t * t
    }
    if (value < 90) {
      const t = (value - 70) / 20
      return 0.30 + 0.25 * t
    }
    const t = Math.max(0, Math.min(1, (value - 90) / Math.max(1, tip - 90)))
    return 0.88 + 0.10 * t
  }
}

export function legendGradient(legend: Legend): string {
  if (legend.categorical) return ''
  const min = legend.stops[0].value
  const max = legend.stops[legend.stops.length - 1].value
  const span = max - min || 1
  const ramp = legend.emphasiseTop ? createOpacityRamp(legend) : null

  const parts = legend.stops.map((stop) => {
    const { r, g, b } = hexToRgb(stop.color)
    const opacity = ramp ? ramp(stop.value) : 1

    return `rgb(${r} ${g} ${b} / ${Math.round(opacity * 100)}%) ${Math.round(((stop.value - min) / span) * 100)}%`
  })

  return `linear-gradient(to right, ${parts.join(', ')})`
}
