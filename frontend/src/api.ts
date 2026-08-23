import axios from 'axios'
import type { MapResponse, Region, Species } from './types'

const client = axios.create({ baseURL: '/api' })

export async function fetchRegions(): Promise<Region[]> {
  const { data } = await client.get<{ regions: Region[] }>('/regions')
  return data.regions
}

export async function fetchSpecies(): Promise<Species[]> {
  const { data } = await client.get<{ species: Species[] }>('/species')
  return data.species
}

export async function fetchMap(params: {
  region: string
  species: string
  resolution: number
  south: number
  west: number
  north: number
  east: number
}): Promise<MapResponse> {
  const { data } = await client.get<MapResponse>('/map', { params, timeout: 120000 })
  return data
}
