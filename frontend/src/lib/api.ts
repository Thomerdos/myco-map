import type { Bounds, LayerGrid, LocationReport, MapContext, ScoreProjection } from '../types'

const BASE = '/api'

async function get<T>(path: string, params?: Record<string, string | number>): Promise<T> {
  const url = new URL(BASE + path, window.location.origin)
  Object.entries(params ?? {}).forEach(([key, value]) => {
    url.searchParams.set(key, String(value))
  })

  const response = await fetch(url.toString())
  if (!response.ok) {
    const payload = await response.json().catch(() => ({}))
    throw new Error(payload.error ?? `Erreur ${response.status}`)
  }

  return response.json() as Promise<T>
}

export function fetchContext(): Promise<MapContext> {
  return get<MapContext>('/context')
}

export function fetchLayer(params: {
  layer: string
  species: string
  bounds: Bounds
  maxCells: number
  mode?: 'moment' | 'habitat'
  date?: string
}): Promise<LayerGrid> {
  return get<LayerGrid>('/layer', {
    layer: params.layer,
    species: params.species,
    maxCells: params.maxCells,
    mode: params.mode ?? 'moment',
    ...(params.date ? { date: params.date } : {}),
    south: params.bounds.south,
    west: params.bounds.west,
    north: params.bounds.north,
    east: params.bounds.east,
  })
}

export function fetchLocation(params: {
  lat: number
  lng: number
  species: string
  mode?: 'moment' | 'habitat'
  date?: string
}): Promise<LocationReport> {
  return get<LocationReport>('/location', {
    lat: params.lat,
    lng: params.lng,
    species: params.species,
    mode: params.mode ?? 'moment',
    ...(params.date ? { date: params.date } : {}),
  })
}

export function fetchProjection(params: {
  species: string
  bounds: Bounds
}): Promise<ScoreProjection> {
  return get<ScoreProjection>('/projection', {
    species: params.species,
    south: params.bounds.south,
    west: params.bounds.west,
    north: params.bounds.north,
    east: params.bounds.east,
  })
}
