export interface Bounds {
  south: number
  west: number
  north: number
  east: number
}

export interface LegendStop {
  value: number
  label: string
  color: string
}

export interface Legend {
  title: string
  unit: string | null
  categorical: boolean
  stops: LegendStop[]
  emphasiseTop?: boolean
}

export interface HarvestWindow {
  start: string
  end: string
  peak: boolean
  label: string
}

export interface SpeciesSummary {
  id: string
  name: string
  scientificName: string
  summary: string
  hostTrees: string
  harvestWindows: HarvestWindow[]
  altitude: {
    min: number
    optimumLow: number
    optimumHigh: number
    max: number
  }
}

export interface LayerDescriptor {
  id: string
  label: string
  categorical: boolean
  unit: string | null
  requiresSpecies: boolean
}

export interface MapContext {
  area: {
    name: string
    bounds: Bounds
    center: { lat: number; lng: number }
    zoom: number
    cellSize: number
  }
  ready: boolean
  projection: {
    horizonDays: number
    from: string
    to: string
  }
  grid: {
    cellSize: number
    columns: number
    rows: number
    bounds: Bounds
  } | null
  layers: LayerDescriptor[]
  species: SpeciesSummary[]
}

export interface ProjectionDay {
  date: string
  offset: number
  label: string
  average: number | null
  best: number | null
  inSeason: boolean
  weather: Weather
}

export interface ScoreProjection {
  species: string
  from: string
  to: string
  days: ProjectionDay[]
}

export interface Weather {
  triggerRain: number
  recentRain: number
  fortnightRain: number
  temperature: number
  humidity: number
  soilMoisture: number
  daysSinceSoakingRain: number | null
  soakingRain: number
  accumulatedRain: number
  rainSinceSoaking?: number
  litterSoilMoisture?: number
  brokeDrySpell: boolean
  driedOutAfterSoaking?: boolean
  label: string
  degraded?: boolean
  soakingEvents?: { daysSince: number; millimetres: number }[]
  flushDaysSince?: number | null
  flushPhase?: string
  flushMillimetres?: number | null
}

export interface Sector {
  lat: number
  lng: number
  cells: number
  areaHa: number
  minScore: number
  maxScore: number
  average: number
  elevation: number
  exposure: string
  cover: string
  hostTree: string
  hostTreeCode: number
  canopy: string
  canopyCode: number
  canopyCover?: number | null
  accessMeters?: number
}

export interface LayerGrid {
  layer: string
  layerLabel: string
  scoringMode: 'moment' | 'habitat'
  asOfDate: string | null
  legend: Legend
  bounds: Bounds
  columns: number
  rows: number
  cellSize: number
  values: (number | null)[]
  statistics: {
    cells: number
    average: number | null
    best: number | null
    resolution: number
    hotspotThreshold?: number
  }
  sectors: Sector[]
  sparseNulls?: boolean
  weather: Weather
  species: {
    id: string
    name: string
    scientificName: string
    summary: string
    hostTrees: string
    inSeason: boolean
    seasonGate?: string
    activeWindow: HarvestWindow | null
    nextWindow: HarvestWindow | null
    harvestWindows: HarvestWindow[]
  } | null
}

export interface CriterionDetail {
  criterion: string
  label: string
  value: number
  weight: number
  rationale: string
  explanation: string
}

export interface LocationHorizonDay {
  date: string
  offset: number
  label: string
  score: number
  inSeason: boolean
  weather: Weather
}

export interface AccessWalk {
  reachable: boolean
  meters: number
  minutes: number
  alongMeters: number
  approachMeters: number
  start: { lat: number; lng: number } | null
  coordinates: { lat: number; lng: number }[]
  approachFromIndex: number
}

export interface LocationReport {
  coordinates: { lat: number; lng: number }
  terrain: {
    elevation: number
    slope: number
    aspect: number
    exposure: string
    coolness: number
    curvature: number
    cover: string
    coverCode: number
    hostTree: string
    hostTreeCode: number
    canopy: string
    canopyCode: number
    canopyCover?: number | null
    edgeDistance: number
    waterDistance: number
    accessDistance?: number
    moisture: number
    geology: string
    geologyCode: number
    soilPh?: number | null
  }
  weather: Weather
  scoringMode: 'moment' | 'habitat'
  asOfDate: string | null
  species: {
    id: string
    name: string
    scientificName: string
    summary: string
    hostTrees: string
  }
  score: number
  level: string
  levelLabel: string
  inSeason: boolean
  seasonGate?: string
  activeWindow: HarvestWindow | null
  nextWindow: HarvestWindow | null
  breakdown: CriterionDetail[]
  drivers: CriterionDetail[]
  allSpecies: {
    id: string
    name: string
    score: number
    level: string
    levelLabel: string
    inSeason: boolean
  }[]
  horizon?: LocationHorizonDay[]
  accessWalk?: AccessWalk
}
