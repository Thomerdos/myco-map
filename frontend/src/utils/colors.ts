export const LEVEL_COLORS: Record<string, string> = {
  excellent: '#1b7f4a',
  bon: '#4caf50',
  moyen: '#f0ad4e',
  faible: '#d9534f',
}

export function scoreColor(score: number): string {
  if (score >= 75) return LEVEL_COLORS.excellent
  if (score >= 60) return LEVEL_COLORS.bon
  if (score >= 45) return LEVEL_COLORS.moyen
  return LEVEL_COLORS.faible
}

export function levelLabel(level: string): string {
  return {
    excellent: 'Excellent',
    bon: 'Bon',
    moyen: 'Moyen',
    faible: 'Faible',
  }[level] ?? level
}

export function forestLabel(type: string): string {
  return {
    feuillu: 'Forêt feuillue',
    conifere: 'Forêt de conifères',
    mixte: 'Forêt mixte',
    forestier: 'Forêt',
    non_forestier: 'Non forestier',
  }[type] ?? type
}
