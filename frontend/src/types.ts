export interface Region {
  id: string
  name: string
  bounds: { south: number; west: number; north: number; east: number }
  center: { lat: number; lng: number }
}

export interface HarvestWindow {
  start: string
  end: string
  peak: boolean
  label: string
}

export interface Species {
  id: string
  name: string
  scientificName: string
  description: string
  harvestWindows: HarvestWindow[]
  inSeason?: boolean
  activeWindow?: HarvestWindow | null
}

export interface Terrain {
  elevation: number
  slope: number
  aspect: number
  aspectLabel: string
  forestType: string
  forestConfidence: number
}

export interface MapCell {
  id: string
  lat: number
  lng: number
  score: number
  level: 'excellent' | 'bon' | 'moyen' | 'faible'
  reasons: string[]
  terrain?: Terrain
}

export interface MapResponse {
  region: Region
  species: Species
  weather: {
    precipitation7d: number
    precipitation14d: number
    avgTemp: number
    humidity: number
    label: string
    reasons: string[]
  }
  resolution: number
  bounds: { south: number; west: number; north: number; east: number }
  cells: MapCell[]
  stats: {
    count: number
    avgScore: number
    topScore: number
    excellent?: number
    bon?: number
  }
}

export type MapViewMode = 'heatmap' | 'grid' | 'circles'
