<script setup lang="ts">
import { Button, Tooltip } from '@vuetify/v0'
import { computed, shallowRef } from 'vue'
import type { LayerDescriptor, LayerGrid, ScoreProjection, SpeciesSummary } from '../types'
import { legendGradient } from '../lib/colorScale'
import RangeSlider from './ui/RangeSlider.vue'
import SelectControl from './ui/SelectControl.vue'
import SwitchControl from './ui/SwitchControl.vue'
import type { SelectGroup } from './ui/SelectControl.vue'

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
  accessibleOnly: boolean
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
  'update:accessibleOnly': [value: boolean]
  'update:basemap': [value: string]
}>()

const BASEMAP_OPTIONS = [
  { value: 'plan', label: 'Plan' },
  { value: 'topo', label: 'Topo' },
  { value: 'satellite', label: 'Satellite' },
]

const LAYER_GROUPS: { label: string, ids: string[] }[] = [
  { label: 'Décision', ids: ['potential', 'access'] },
  { label: 'Terrain', ids: ['elevation', 'exposure', 'slope', 'moisture', 'edge'] },
  { label: 'Peuplement', ids: ['cover', 'stand_density', 'geology'] },
  { label: 'Météo', ids: ['weather'] },
]

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
const accessibleOnlyLocal = computed({
  get: () => props.accessibleOnly,
  set: (value: boolean) => emit('update:accessibleOnly', value),
})
const activeSpeciesLocal = computed({
  get: () => props.activeSpecies,
  set: (value: string) => emit('update:activeSpecies', value),
})
const activeLayerLocal = computed({
  get: () => props.activeLayer,
  set: (value: string) => emit('update:activeLayer', value),
})

const seasonTipOpen = shallowRef(false)

const speciesOptions = computed(() =>
  props.species.map((item) => ({ value: item.id, label: item.name })),
)

const selectedSpecies = computed(() =>
  props.species.find((item) => item.id === props.activeSpecies) ?? null,
)

const scientificName = computed(() =>
  props.grid?.species?.scientificName ?? selectedSpecies.value?.scientificName ?? null,
)

const layerGroups = computed<SelectGroup[]>(() => {
  const byId = new Map(props.layers.map((layer) => [layer.id, layer]))
  const grouped = new Set<string>()
  const groups: SelectGroup[] = []

  for (const group of LAYER_GROUPS) {
    const options = group.ids.flatMap((id) => {
      const layer = byId.get(id)
      if (!layer) return []
      grouped.add(id)
      return [{ value: layer.id, label: layer.label }]
    })
    if (options.length > 0) {
      groups.push({ label: group.label, options })
    }
  }

  const leftover = props.layers.filter((layer) => !grouped.has(layer.id))
  if (leftover.length > 0) {
    groups.push({
      label: 'Autres',
      options: leftover.map((layer) => ({ value: layer.id, label: layer.label })),
    })
  }

  return groups
})

const showSeasonTooltip = computed(() =>
  !props.habitatOnly && props.grid?.species != null,
)

const seasonTitle = computed(() => {
  if (!showSeasonTooltip.value || !props.grid?.species) return null
  return props.grid.species.inSeason ? 'En saison' : 'Hors saison'
})

const seasonDetail = computed(() => {
  const species = props.grid?.species
  if (!showSeasonTooltip.value || !species) return null
  if (species.activeWindow) return species.activeWindow.label
  if (species.nextWindow) return `Prochaine fenêtre : ${species.nextWindow.label}`
  return null
})

function emitChoice(event: 'update:basemap', value: unknown) {
  if (value == null || value === '') return
  emit(event, String(value))
}
</script>

<template>
  <div class="flex flex-col gap-4 bg-surface px-4 py-4">
    <section>
      <h2 class="mb-2 text-[0.74rem] font-bold uppercase tracking-wider text-secondary">Espèce recherchée</h2>
      <SelectControl
        v-model="activeSpeciesLocal"
        :options="speciesOptions"
        aria-label="Espèce recherchée"
      />

      <Tooltip.Root
        v-if="scientificName && showSeasonTooltip"
        v-model="seasonTipOpen"
        interactive
      >
        <Tooltip.Activator renderless v-slot="{ attrs, styles }">
          <button
            type="button"
            class="mt-2 flex w-full cursor-help items-center justify-between gap-2 rounded-sm text-left text-xs text-secondary outline-none focus-visible:ring-2 focus-visible:ring-primary"
            :style="styles"
            :aria-describedby="attrs['aria-describedby']"
            :data-state="attrs['data-state']"
            :aria-label="seasonTitle ? `${scientificName} — ${seasonTitle}` : scientificName"
            @pointerenter="attrs.onPointerenter"
            @pointerleave="attrs.onPointerleave"
            @focus="attrs.onFocus"
            @blur="attrs.onBlur"
            @keydown="attrs.onKeydown"
            @click.stop="seasonTipOpen = !seasonTipOpen"
          >
            <em>{{ scientificName }}</em>
            <span
              class="inline-block h-2 w-2 shrink-0 rounded-full"
              :class="props.grid?.species?.inSeason ? 'bg-success' : 'bg-[#c48a2a]'"
              aria-hidden="true"
            />
          </button>
        </Tooltip.Activator>
        <Tooltip.Content
          class="z-[1200] max-w-56 rounded-md border border-[#d9d0bc] bg-surface px-2.5 py-2 text-xs leading-snug text-on-surface shadow-lg"
        >
          <p class="m-0 font-bold">{{ seasonTitle }}</p>
          <p v-if="seasonDetail" class="mt-1 mb-0 text-secondary">{{ seasonDetail }}</p>
        </Tooltip.Content>
      </Tooltip.Root>
      <p v-else-if="scientificName" class="mt-2 text-xs text-secondary">
        <em>{{ scientificName }}</em>
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
      <SelectControl
        v-model="activeLayerLocal"
        :groups="layerGroups"
        aria-label="Masque affiché"
      />
    </section>

    <section v-if="props.grid">
      <h2 class="mb-2 text-[0.74rem] font-bold uppercase tracking-wider text-secondary">{{ props.grid.legend.title }}</h2>
      <div v-if="!props.grid.legend.categorical">
        <div class="h-3 rounded-md border border-[#cfc6b1]" :style="{ background: legendGradient(props.grid.legend) }" />
        <div class="mt-1 flex justify-between gap-1 text-[0.68rem] text-secondary">
          <span v-for="stop in props.grid.legend.stops.filter((item) => item.label)" :key="stop.value">{{ stop.label }}</span>
        </div>
      </div>
      <ul v-else class="m-0 flex list-none flex-col gap-1 p-0 text-sm">
        <li v-for="stop in props.grid.legend.stops" :key="stop.value" class="flex items-center gap-2">
          <span class="h-3.5 w-3.5 rounded-sm border border-[#b9b1a0]" :style="{ background: stop.color }" />
          {{ stop.label }}
        </li>
      </ul>
      <p class="mt-2 text-xs text-secondary">
        {{ props.grid.statistics.resolution }} m ·
        {{ props.grid.statistics.cells.toLocaleString('fr-FR') }} mailles
        <span v-if="props.loading"> · calcul en cours…</span>
      </p>
    </section>

    <section>
      <h2 class="mb-2 text-[0.74rem] font-bold uppercase tracking-wider text-secondary">Affichage</h2>
      <p class="mb-1 text-xs text-secondary">Opacité du masque</p>
      <RangeSlider v-model="opacityLocal" :min="0.2" :max="1" :step="0.05" />
      <div class="mt-2 flex flex-col gap-2">
        <SwitchControl v-model="showContoursLocal">Lignes de niveau des zones</SwitchControl>
        <SwitchControl v-model="showSpotsLocal">Secteurs à 90 ou plus</SwitchControl>
        <SwitchControl
          v-if="props.activeLayer === 'potential'"
          v-model="accessibleOnlyLocal"
        >
          Masquer les zones peu accessibles
        </SwitchControl>
      </div>
      <Button.Group
        class="mt-3 flex w-full gap-1"
        mandatory
        :model-value="props.basemap"
        @update:model-value="emitChoice('update:basemap', $event)"
      >
        <Button.Root
          v-for="opt in BASEMAP_OPTIONS"
          :key="opt.value"
          :value="opt.value"
          class="flex-1 cursor-pointer rounded-md border px-2 py-1.5 text-xs"
          :class="opt.value === props.basemap
            ? 'border-primary bg-primary text-on-primary'
            : 'border-[#d4cbb7] bg-[#fffdf8]'"
        >
          <Button.Content>{{ opt.label }}</Button.Content>
        </Button.Root>
      </Button.Group>
    </section>
  </div>
</template>
