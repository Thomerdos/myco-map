import type { Legend, Weather } from '../types'
import { createColorScale } from './colorScale'

/**
 * Same stops as LayerLegendFactory::potentialLegend (« Feu » linéaire 70–100).
 */
const POTENTIAL_LEGEND: Legend = {
  title: '',
  unit: null,
  categorical: false,
  emphasiseTop: true,
  stops: [
    { value: 0, label: 'À éviter', color: '#0c0e14' },
    { value: 60, label: '', color: '#1e2430' },
    { value: 70, label: '70', color: '#8b5e12' },
    { value: 75, label: '', color: '#b8730a' },
    { value: 80, label: '80', color: '#d98900' },
    { value: 85, label: 'Correct', color: '#f0a000' },
    { value: 90, label: '90', color: '#ff6b1a' },
    { value: 95, label: 'Prometteur', color: '#dc2626' },
    { value: 100, label: 'Top', color: '#fff5e6' },
  ],
}

const potentialScale = createColorScale(POTENTIAL_LEGEND)

export function potentialColor(score: number | null): string {
  if (score === null) return '#4a5568'
  const { r, g, b } = potentialScale(score)
  return `rgb(${r} ${g} ${b})`
}

export function roundedScore(score: number | null): number | null {
  return score === null ? null : Math.round(score)
}

/**
 * Warm ink aligned with the Feu ramp: amber from 70, orange from 85, red from 95.
 */
export function scoreInk(score: number | null): string {
  const n = roundedScore(score)
  if (n === null) return '#6a6153'
  if (n >= 95) return '#b91c1c'
  if (n >= 85) return '#ea580c'
  if (n >= 70) return '#a16207'
  return '#2b261f'
}

export function scoreColor(score: number): string {
  return scoreInk(score)
}

export function criterionColor(value: number): string {
  if (value >= 85) return '#ea580c'
  if (value >= 70) return '#a16207'
  if (value >= 40) return '#78716c'
  if (value >= 20) return '#57534e'
  return '#5b5f6b'
}

/** Sparkline fill across the visible Feu band (70–100). */
export function projectionMeterPercent(score: number | null): number {
  const n = roundedScore(score)
  if (n === null) return 0
  return Math.max(8, Math.min(100, ((n - 70) / 30) * 100))
}

/** Phase marks: peak is warm so it separates from teal “pousse”; no map magenta. */
const FLUSH_MARK_COLORS: Record<string, string> = {
  none: '#94a3b8',
  incubating: '#6b8aa8',
  starting: '#0f766e',
  peak: '#c2410c',
  declining: '#475569',
  lingering: '#64748b',
  spent: '#94a3b8',
  dried: '#c45c12',
}

const FLUSH_LEGEND_ORDER = [
  'none',
  'incubating',
  'starting',
  'peak',
  'declining',
  'lingering',
  'spent',
  'dried',
] as const

const FLUSH_LEGEND_LABELS: Record<(typeof FLUSH_LEGEND_ORDER)[number], string> = {
  none: 'Sans déclencheur',
  incubating: 'Incubation',
  starting: 'Pousse',
  peak: 'Pic',
  declining: 'Fin',
  lingering: 'Cueillable',
  spent: 'Passé',
  dried: 'Asséché',
}

export function flushLegendKey(weather: Weather): string {
  if (weather.driedOutAfterSoaking) return 'dried'
  return weather.flushPhase ?? 'none'
}

export function flushMarkColor(weather: Weather): string {
  return FLUSH_MARK_COLORS[flushLegendKey(weather)] ?? FLUSH_MARK_COLORS.none
}

export function flushLegendLabel(weather: Weather): string {
  const key = flushLegendKey(weather)
  return FLUSH_LEGEND_LABELS[key as keyof typeof FLUSH_LEGEND_LABELS] ?? FLUSH_LEGEND_LABELS.none
}

export function flushLegendItems(weathers: Weather[]): { key: string; label: string; color: string }[] {
  const present = new Set(weathers.map(flushLegendKey))
  return FLUSH_LEGEND_ORDER
    .filter((key) => present.has(key))
    .map((key) => ({
      key,
      label: FLUSH_LEGEND_LABELS[key],
      color: FLUSH_MARK_COLORS[key],
    }))
}
