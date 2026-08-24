<script setup lang="ts">
import { Button, Single } from '@vuetify/v0'
import { computed } from 'vue'
import type { LayerDescriptor, LayerGrid, ScoreProjection, SpeciesSummary } from '../types'
import { legendGradient } from '../lib/colorScale'
import RangeSlider from './ui/RangeSlider.vue'
import SwitchControl from './ui/SwitchControl.vue'

const props = defineProps<{
  layers: LayerDescriptor[]
  species: SpeciesSummary[]
  activeLayer: string
  activeSpecies: string
  habitatOnly: boolean
  projectionDate: string
  projectionFrom: string
  projectionTo: string
  projection: ScoreProjection | null
  opacity: number
  showContours: boolean
  showSpots: boolean
  basemap: string
  grid: LayerGrid | null
  loading: boolean
}>()

const emit = defineEmits<{
  'update:activeLayer': [value: string]
  'update:activeSpecies': [value: string]
  'update:habitatOnly': [value: boolean]
  'update:projectionDate': [value: string]
  'update:opacity': [value: number]
  'update:showContours': [value: boolean]
  'update:showSpots': [value: boolean]
  'update:basemap': [value: string]
}>()

const BASEMAP_OPTIONS = [
  { value: 'plan', label: 'Plan' },
  { value: 'topo', label: 'Topo' },
  { value: 'satellite', label: 'Satellite' },
]

const weatherTitle = computed(() => {
  if (!props.projectionDate || props.projectionDate === props.projectionFrom) {
    return 'Conditions du moment'
  }
  const date = new Date(`${props.projectionDate}T12:00:00`)
  return `Conditions au ${date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })}`
})

const projectionLabel = computed(() => {
  if (!props.projectionDate) return ''
  const date = new Date(`${props.projectionDate}T12:00:00`)
  return date.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
})

const habitatOnlyLocal = computed({
  get: () => props.habitatOnly,
  set: (value: boolean) => emit('update:habitatOnly', value),
})
const opacityLocal = computed({
  get: () => props.opacity,
  set: (value: number) => emit('update:opacity', value),
})
const showContoursLocal = computed({
  get: () => props.showContours,
  set: (value: boolean) => emit('update:showContours', value),
})
const showSpotsLocal = computed({
  get: () => props.showSpots,
  set: (value: boolean) => emit('update:showSpots', value),
})
</script>

<template>
  <div class="flex flex-col gap-4 bg-surface px-4 py-4">
    <section>
      <h2 class="mb-2 text-[0.74rem] font-bold uppercase tracking-wider text-secondary">Espèce recherchée</h2>
      <Single.Root
        :model-value="props.activeSpecies"
        class="grid grid-cols-2 gap-1.5"
        @update:model-value="emit('update:activeSpecies', String($event))"
      >
        <Single.Item
          v-for="item in props.species"
          :key="item.id"
          :value="item.id"
          v-slot="{ isSelected }"
        >
          <span
            class="block cursor-pointer rounded-md border px-2 py-1.5 text-center text-[0.82rem]"
            :class="isSelected ? 'border-primary bg-primary text-on-primary font-semibold' : 'border-[#d4cbb7] bg-[#fffdf8]'"
          >
            {{ item.name }}
          </span>
        </Single.Item>
      </Single.Root>

      <p v-if="props.grid?.species" class="mt-2 flex items-center justify-between text-xs text-secondary">
        <em>{{ props.grid.species.scientificName }}</em>
        <span
          v-if="!props.habitatOnly"
          class="rounded-full px-2 py-0.5 text-[0.7rem] font-bold"
          :class="props.grid.species.inSeason ? 'bg-[#d6ecd9] text-success' : 'bg-[#f2e2c4] text-[#8a5a12]'"
        >
          {{ props.grid.species.inSeason ? 'En saison' : 'Hors saison' }}
        </span>
      </p>
      <p v-if="!props.habitatOnly && props.grid?.species?.activeWindow" class="mt-1 text-[0.79rem]">
        {{ props.grid.species.activeWindow.label }}
      </p>
      <p v-else-if="!props.habitatOnly && props.grid?.species?.nextWindow" class="mt-1 text-[0.79rem]">
        Prochaine fenêtre : {{ props.grid.species.nextWindow.label }}
      </p>

      <div class="mt-3 rounded-md border border-[#d4cbb7] bg-[#fffdf8] px-2.5 py-2">
        <SwitchControl v-model="habitatOnlyLocal">
          <strong>Potentiel d'habitat</strong>
          <span class="text-secondary"> — ignorer météo et saison</span>
        </SwitchControl>
      </div>
    </section>

    <section v-if="!props.habitatOnly && props.projectionFrom">
      <h2 class="mb-2 text-[0.74rem] font-bold uppercase tracking-wider text-secondary">Projection au</h2>
      <p class="mb-1 text-sm">{{ projectionLabel }}</p>
      <input
        type="date"
        class="w-full rounded-md border border-[#d4cbb7] bg-[#fffdf8] px-2 py-1.5 text-sm"
        :value="props.projectionDate"
        :min="props.projectionFrom"
        :max="props.projectionTo"
        @change="emit('update:projectionDate', ($event.target as HTMLInputElement).value)"
      />
      <p class="mt-1 text-xs leading-snug text-secondary">
        Météo et saison recalées sur la date choisie (prévisions Open-Meteo jusqu'à J+14).
      </p>
    </section>

    <section>
      <h2 class="mb-2 text-[0.74rem] font-bold uppercase tracking-wider text-secondary">Masque affiché</h2>
      <Single.Root
        :model-value="props.activeLayer"
        class="flex flex-col gap-1"
        @update:model-value="emit('update:activeLayer', String($event))"
      >
        <Single.Item
          v-for="layer in props.layers"
          :key="layer.id"
          :value="layer.id"
          v-slot="{ isSelected }"
        >
          <span
            class="flex cursor-pointer items-center justify-between rounded-md border px-2.5 py-1.5 text-left text-[0.83rem]"
            :class="isSelected ? 'border-primary bg-[#e3ecdf] font-semibold' : 'border-transparent bg-[#fffdf8]'"
          >
            <span>{{ layer.label }}</span>
            <span v-if="layer.unit" class="text-xs text-secondary">{{ layer.unit }}</span>
          </span>
        </Single.Item>
      </Single.Root>
    </section>

    <section v-if="props.grid">
      <h2 class="mb-2 text-[0.74rem] font-bold uppercase tracking-wider text-secondary">{{ props.grid.legend.title }}</h2>
      <div v-if="!props.grid.legend.categorical">
        <div class="h-3 rounded-md border border-[#cfc6b1]" :style="{ background: legendGradient(props.grid.legend) }" />
        <div class="mt-1 flex justify-between gap-1 text-[0.68rem] text-secondary">
          <span v-for="stop in props.grid.legend.stops" :key="stop.value">{{ stop.label }}</span>
        </div>
      </div>
      <ul v-else class="m-0 flex list-none flex-col gap-1 p-0 text-sm">
        <li v-for="stop in props.grid.legend.stops" :key="stop.value" class="flex items-center gap-2">
          <span class="h-3.5 w-3.5 rounded-sm border border-[#b9b1a0]" :style="{ background: stop.color }" />
          {{ stop.label }}
        </li>
      </ul>
    </section>

    <section>
      <h2 class="mb-2 text-[0.74rem] font-bold uppercase tracking-wider text-secondary">Affichage</h2>
      <p class="mb-1 text-xs text-secondary">Opacité du masque</p>
      <RangeSlider v-model="opacityLocal" :min="0.2" :max="1" :step="0.05" />
      <div class="mt-2 flex flex-col gap-2">
        <SwitchControl v-model="showContoursLocal">Lignes de niveau des zones</SwitchControl>
        <SwitchControl v-model="showSpotsLocal">Secteurs à 90 ou plus</SwitchControl>
      </div>
      <Button.Group
        class="mt-3 flex w-full gap-1"
        :model-value="props.basemap"
        @update:model-value="emit('update:basemap', String($event))"
      >
        <Button.Root
          v-for="opt in BASEMAP_OPTIONS"
          :key="opt.value"
          :value="opt.value"
          class="flex-1 rounded-md border border-[#d4cbb7] bg-[#fffdf8] px-2 py-1.5 text-xs data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-on-primary"
        >
          <Button.Content>{{ opt.label }}</Button.Content>
        </Button.Root>
      </Button.Group>
    </section>

    <section v-if="props.grid && !props.habitatOnly">
      <h2 class="mb-2 text-[0.74rem] font-bold uppercase tracking-wider text-secondary">{{ weatherTitle }}</h2>
      <ul class="m-0 flex list-none flex-col gap-1 p-0 text-[0.81rem]">
        <li><strong>{{ props.grid.weather.triggerRain }} mm</strong> de pluie déclenchante (J-14 à J-5)</li>
        <li><strong>{{ props.grid.weather.recentRain }} mm</strong> ces 5 derniers jours</li>
        <li><strong>{{ props.grid.weather.temperature }} °C</strong> en moyenne</li>
        <li><strong>{{ props.grid.weather.humidity }} %</strong> d'humidité de l'air</li>
        <li class="font-bold text-primary">{{ props.grid.weather.label }}</li>
      </ul>
      <p v-if="props.grid.weather.degraded" class="mt-2 text-xs text-warning">
        Données météo indisponibles, valeurs de repli utilisées.
      </p>
      <p class="mt-2 text-xs text-secondary">
        Maille affichée : {{ props.grid.statistics.resolution }} m ·
        {{ props.grid.statistics.cells.toLocaleString('fr-FR') }} mailles
        <span v-if="props.loading"> · calcul en cours…</span>
      </p>
    </section>

    <section v-else-if="props.grid && props.habitatOnly">
      <h2 class="mb-2 text-[0.74rem] font-bold uppercase tracking-wider text-secondary">Potentiel d'habitat</h2>
      <p class="m-0 text-[0.81rem] leading-snug text-secondary">
        Score recalculé sans météo ni saison : seuls le terrain et le couvert comptent.
      </p>
      <p class="mt-2 text-xs text-secondary">
        Maille affichée : {{ props.grid.statistics.resolution }} m ·
        {{ props.grid.statistics.cells.toLocaleString('fr-FR') }} mailles
      </p>
    </section>
  </div>
</template>
