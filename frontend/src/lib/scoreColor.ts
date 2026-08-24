export function scoreColor(score: number): string {
  if (score >= 96) return '#a21caf'
  if (score >= 90) return '#c026d3'
  if (score >= 78) return '#b45309'
  if (score >= 62) return '#57534e'
  return '#5b5f6b'
}

export function criterionColor(value: number): string {
  if (value >= 80) return '#c026d3'
  if (value >= 60) return '#b45309'
  if (value >= 40) return '#7e22ce'
  if (value >= 20) return '#57534e'
  return '#5b5f6b'
}

export function roundedScore(score: number | null): number | null {
  return score === null ? null : Math.round(score)
}

/**
 * Ink color for the 15-day strip. Magenta only for rounded hotspots (≥ 90), matching
 * the potential mask. Everything below stays in the paper ink — warm gold/khaki on
 * cream reads as olive and fights the bar.
 */
export function projectionScoreColor(score: number | null): string {
  const n = roundedScore(score)
  if (n === null) return '#6a6153'
  if (n >= 90) return '#c026d3'
  return '#2b261f'
}

/** Sparkline fill, stretched across the 70–100 band so 88 vs 95 stays readable. */
export function projectionMeterPercent(score: number | null): number {
  const n = roundedScore(score)
  if (n === null) return 0
  return Math.max(10, Math.min(100, ((n - 70) / 30) * 100))
}
