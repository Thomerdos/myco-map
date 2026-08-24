export function scoreColor(score: number): string {
  if (score >= 96) return '#a86a00'
  if (score >= 90) return '#c2600f'
  if (score >= 78) return '#6f4a78'
  if (score >= 62) return '#4a4a7a'
  return '#5b5f6b'
}

export function criterionColor(value: number): string {
  if (value >= 80) return '#c2600f'
  if (value >= 60) return '#a8384f'
  if (value >= 40) return '#6f4a78'
  if (value >= 20) return '#4a4a7a'
  return '#5b5f6b'
}

export function scoreTone(score: number | null): string {
  if (score === null) return 'tone-none'
  if (score >= 90) return 'tone-high'
  if (score >= 78) return 'tone-mid'
  if (score >= 62) return 'tone-low'
  return 'tone-poor'
}
