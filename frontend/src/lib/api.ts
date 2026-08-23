import type { Bounds, LayerGrid, LocationReport, MapContext } from '../types'

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
}): Promise<LayerGrid> {
  return get<LayerGrid>('/layer', {
    layer: params.layer,
    species: params.species,
    maxCells: params.maxCells,
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
}): Promise<LocationReport> {
  return get<LocationReport>('/location', params)
}
