import type { Legend, Weather } from '../types'
import { createColorScale } from './colorScale'

/**
 * Same stops as LayerLegendFactory::potentialLegend so UI ink matches the mask.
 */
const POTENTIAL_LEGEND: Legend = {
  title: '',
  unit: null,
  categorical: false,
  emphasiseTop: true,
  stops: [
    { value: 0, label: 'À éviter', color: '#14161e' },
    { value: 40, label: 'Faible', color: '#2a3142' },
    { value: 62, label: 'Moyen', color: '#4a5568' },
    { value: 78, label: 'Correct', color: '#ffc01a' },
    { value: 86, label: '', color: '#ffd24d' },
    { value: 90, label: 'Prometteur', color: '#c026d3' },
    { value: 96, label: 'Exceptionnel', color: '#ff4ef0' },
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
 * High-contrast ink for scores on cream paper. Magenta only for hotspots (≥ 90),
 * matching the mask. Gold from the map ramp is unreadable at text size.
 */
export function scoreInk(score: number | null): string {
  const n = roundedScore(score)
  if (n === null) return '#6a6153'
  if (n >= 90) return '#c026d3'
  return '#2b261f'
}

export function scoreColor(score: number): string {
  return scoreInk(score)
}

export function criterionColor(value: number): string {
  if (value >= 80) return '#c026d3'
  if (value >= 60) return '#b45309'
  if (value >= 40) return '#7e22ce'
  if (value >= 20) return '#57534e'
  return '#5b5f6b'
}

/** Sparkline fill, stretched across the 70–100 band so 88 vs 95 stays readable. */
export function projectionMeterPercent(score: number | null): number {
  const n = roundedScore(score)
  if (n === null) return 0
  return Math.max(10, Math.min(100, ((n - 70) / 30) * 100))
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
