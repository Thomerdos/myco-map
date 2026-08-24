<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ExpansionPanel, Progress } from '@vuetify/v0'
import type { LocationReport, Sector, Weather } from '../types'
import { criterionColor, scoreColor } from '../lib/scoreColor'
import WeatherBlock from './WeatherBlock.vue'
import DisclosureSection from './ui/DisclosureSection.vue'

const SECTORS_SHOWN = 5

const props = defineProps<{
  report: LocationReport | null
  weather: Weather | null
  projectionDate: string
  projectionFrom: string
  sectors: Sector[]
  accessibleOnly: boolean
  expectSectors: boolean
  habitatOnly: boolean
  loading: boolean
}>()

const emit = defineEmits<{
  highlightPicked: [point: { lat: number; lng: number }]
}>()

const openPanels = ref<string[]>(props.report ? [] : ['sectors'])
const showAllSectors = ref(false)

watch(
  () => props.report != null,
  (hasReport) => {
    const open = new Set(openPanels.value)
    if (hasReport) {
      open.delete('sectors')
    } else {
      open.add('sectors')
    }
    openPanels.value = [...open]
  },
)

const weatherAtPoint = computed(() => props.report != null)

const showWeather = computed(() => !props.habitatOnly && props.weather != null)

const terrainSummary = computed(() => {
  if (!props.report) return ''
  const terrain = props.report.terrain
  const cover = terrain.hostTreeCode ? terrain.hostTree : terrain.cover
  const access = formatAccess(terrain.accessDistance) ?? "hors d'atteinte"
  return `${terrain.elevation} m · ${cover.toLowerCase()} · ${access}`
})

const weatherPreview = computed(() => {
  if (!props.weather) return ''
  const weather = props.weather
  let storm: string
  if (weather.flushDaysSince != null && weather.flushMillimetres != null) {
    storm = `${weather.flushMillimetres} mm d'orage`
  } else if (weather.daysSinceSoakingRain != null) {
    storm = `${weather.soakingRain} mm de pluie`
  } else {
    storm = "Pas d'orage franc"
  }
  return `${storm} · ${weather.accumulatedRain} mm / 26 j`
})

const driversPreview = computed(() => {
  if (!props.report) return ''
  return props.report.drivers
    .slice(0, 2)
    .map((item) => `${item.label} ${Math.round(item.value)}`)
    .join(' · ')
})

const otherSpecies = computed(() => {
  if (!props.report) return []
  const currentId = props.report.species.id
  return props.report.allSpecies.filter((item) => item.id !== currentId)
})

const otherSpeciesPreview = computed(() => {
  if (otherSpecies.value.length === 0) return ''
  const best = otherSpecies.value.reduce((winner, item) =>
    item.score > winner.score ? item : winner,
  )
  return `${best.name} ${Math.round(best.score)}`
})

const showSectorsPanel = computed(() =>
  props.sectors.length > 0
  || (props.expectSectors && props.accessibleOnly && !props.habitatOnly),
)

const sectorsPreview = computed(() => {
  if (props.sectors.length === 0) return 'Aucune tache dans la vue'
  const count = props.sectors.length
  const taches = count > 1 ? `${count} taches` : '1 tache'
  const totalHa = props.sectors.reduce((sum, sector) => sum + sector.areaHa, 0)
  const area = totalHa < 10 ? totalHa.toFixed(1) : String(Math.round(totalHa))
  return `${taches} · ${area} ha`
})

const visibleSectors = computed(() =>
  showAllSectors.value ? props.sectors : props.sectors.slice(0, SECTORS_SHOWN),
)

const hiddenSectorCount = computed(() => Math.max(0, props.sectors.length - SECTORS_SHOWN))

function sectorStand(sector: Sector): string {
  const parts = [sector.hostTreeCode ? sector.hostTree : sector.cover]
  if (sector.canopyCode) parts.push(sector.canopy.toLowerCase())
  return parts.join(', ')
}

function formatAccess(meters: number | undefined): string | null {
  if (meters === undefined || meters >= 9999) return null
  return `chemin à ${meters} m`
}
</script>

<template>
  <div class="flex flex-col gap-3 bg-surface px-4 py-4">
    <section v-if="props.loading && !props.report" class="flex justify-center py-6 text-sm text-secondary">
      Chargement du point…
    </section>

    <section v-else-if="props.report">
      <header class="flex items-baseline gap-3">
        <div class="text-[2.4rem] font-bold leading-none tracking-tight" :style="{ color: scoreColor(props.report.score) }">
          {{ Math.round(props.report.score) }}
          <span class="text-[0.85rem] font-semibold text-secondary">/ 100</span>
        </div>
        <div class="flex flex-col text-sm">
          <strong>{{ props.report.levelLabel }}</strong>
          <span>{{ props.report.species.name }}</span>
          <span class="mt-0.5 text-xs text-secondary">Indice, pas une probabilité de trouver</span>
        </div>
      </header>
      <p class="mt-1 text-xs tabular-nums text-secondary">
        {{ props.report.coordinates.lat.toFixed(4) }}, {{ props.report.coordinates.lng.toFixed(4) }}
      </p>
      <p v-if="props.habitatOnly" class="mt-3 rounded-lg bg-[#e8ecf2] px-2.5 py-2 text-[0.78rem] text-[#2f3d55]">
        Mode potentiel d'habitat — météo et saison ignorées, poids renormalisés.
      </p>
    </section>

    <section v-else class="text-sm leading-snug text-secondary">
      <h3 class="mb-2 text-[0.74rem] font-bold uppercase tracking-wider">Aucun point sélectionné</h3>
      <p class="m-0">
        Cliquez n'importe où sur la carte pour l'indice, le terrain et le détail du score à cet endroit.
      </p>
    </section>

    <WeatherBlock
      v-if="showWeather && props.weather"
      compact
      :weather="props.weather"
      :projection-date="props.projectionDate"
      :projection-from="props.projectionFrom"
      :at-point="weatherAtPoint"
      :coordinates="props.report?.coordinates ?? null"
    />

    <p
      v-if="props.report"
      class="m-0 rounded-lg border border-[#e2dac9] bg-[#fffdf8] px-2.5 py-2 text-[0.82rem] leading-snug"
    >
      {{ terrainSummary }}
    </p>

    <ExpansionPanel.Group v-model="openPanels" multiple class="flex flex-col gap-2">
      <DisclosureSection
        v-if="showWeather && props.weather"
        panel="weather"
        title="Détail météo"
        :preview="weatherPreview"
      >
        <WeatherBlock
          :weather="props.weather"
          :projection-date="props.projectionDate"
          :projection-from="props.projectionFrom"
          :at-point="weatherAtPoint"
          :coordinates="props.report?.coordinates ?? null"
        />
      </DisclosureSection>

      <DisclosureSection
        v-if="props.report"
        panel="terrain"
        title="Fiche terrain"
        :preview="terrainSummary"
      >
        <dl class="m-0 grid grid-cols-2 gap-x-3 gap-y-1.5 text-xs">
          <div class="flex justify-between gap-2 border-b border-dotted border-[#ddd5c3] pb-0.5"><dt class="text-secondary">Altitude</dt><dd class="m-0 font-semibold">{{ props.report.terrain.elevation }} m</dd></div>
          <div class="flex justify-between gap-2 border-b border-dotted border-[#ddd5c3] pb-0.5"><dt class="text-secondary">Pente</dt><dd class="m-0 font-semibold">{{ props.report.terrain.slope }}°</dd></div>
          <div class="flex justify-between gap-2 border-b border-dotted border-[#ddd5c3] pb-0.5"><dt class="text-secondary">Exposition</dt><dd class="m-0 font-semibold">{{ props.report.terrain.exposure }}</dd></div>
          <div class="flex justify-between gap-2 border-b border-dotted border-[#ddd5c3] pb-0.5"><dt class="text-secondary">Couvert</dt><dd class="m-0 font-semibold">{{ props.report.terrain.cover }}</dd></div>
          <div v-if="props.report.terrain.hostTreeCode" class="flex justify-between gap-2 border-b border-dotted border-[#ddd5c3] pb-0.5"><dt class="text-secondary">Essence</dt><dd class="m-0 font-semibold">{{ props.report.terrain.hostTree }}</dd></div>
          <div v-if="props.report.terrain.canopyCode" class="flex justify-between gap-2 border-b border-dotted border-[#ddd5c3] pb-0.5"><dt class="text-secondary">Densité</dt><dd class="m-0 font-semibold">{{ props.report.terrain.canopy }}</dd></div>
          <div class="flex justify-between gap-2 border-b border-dotted border-[#ddd5c3] pb-0.5">
            <dt class="text-secondary">Lisière</dt>
            <dd class="m-0 font-semibold">
              {{ props.report.terrain.edgeDistance >= 0 ? `${props.report.terrain.edgeDistance} m dedans` : `${Math.abs(props.report.terrain.edgeDistance)} m dehors` }}
            </dd>
          </div>
          <div class="flex justify-between gap-2 border-b border-dotted border-[#ddd5c3] pb-0.5"><dt class="text-secondary">Cours d'eau</dt><dd class="m-0 font-semibold">{{ props.report.terrain.waterDistance }} m</dd></div>
          <div class="flex justify-between gap-2 border-b border-dotted border-[#ddd5c3] pb-0.5">
            <dt class="text-secondary">Accès</dt>
            <dd class="m-0 font-semibold">{{ formatAccess(props.report.terrain.accessDistance) ?? 'hors d\'atteinte' }}</dd>
          </div>
          <div class="flex justify-between gap-2 border-b border-dotted border-[#ddd5c3] pb-0.5"><dt class="text-secondary">Humidité</dt><dd class="m-0 font-semibold">{{ props.report.terrain.moisture }} / 100</dd></div>
          <div class="flex justify-between gap-2 border-b border-dotted border-[#ddd5c3] pb-0.5"><dt class="text-secondary">Géologie</dt><dd class="m-0 font-semibold">{{ props.report.terrain.geology }}</dd></div>
        </dl>
      </DisclosureSection>

      <DisclosureSection
        v-if="props.report"
        panel="why"
        title="Pourquoi ce score"
        :preview="driversPreview"
      >
        <ul class="m-0 flex list-none flex-col gap-2.5 p-0">
          <li v-for="criterion in props.report.breakdown" :key="criterion.criterion">
            <div class="mb-1 flex justify-between text-[0.79rem]">
              <span>{{ criterion.label }}</span>
              <span class="font-semibold">{{ Math.round(criterion.value) }}</span>
            </div>
            <Progress.Root :model-value="criterion.value" :max="100">
              <Progress.Track class="h-1.5 overflow-hidden rounded bg-[#e8e1d0]">
                <Progress.Fill class="h-full rounded" :style="{ background: criterionColor(criterion.value) }" />
              </Progress.Track>
            </Progress.Root>
            <p class="m-0 mt-1 text-xs text-secondary">{{ criterion.explanation }}</p>
          </li>
        </ul>
      </DisclosureSection>

      <DisclosureSection
        v-if="otherSpecies.length > 0"
        panel="species"
        title="Autres espèces"
        :preview="otherSpeciesPreview"
      >
        <ul class="m-0 flex list-none flex-col gap-1 p-0 text-[0.81rem]">
          <li v-for="item in otherSpecies" :key="item.id" class="flex justify-between gap-2">
            <span>{{ item.name }}</span>
            <span class="font-bold" :style="{ color: scoreColor(item.score) }">
              {{ Math.round(item.score) }}
              <em v-if="!props.habitatOnly && !item.inSeason" class="ml-1 text-[0.68rem] font-normal not-italic text-secondary">hors saison</em>
            </span>
          </li>
        </ul>
      </DisclosureSection>

      <DisclosureSection
        v-if="showSectorsPanel"
        panel="sectors"
        title="Secteurs à 90+"
        :preview="sectorsPreview"
      >
        <template v-if="props.sectors.length > 0">
          <p class="mb-2 text-xs leading-snug text-secondary">
            Taches continues dans la vue actuelle, classées par surface.
          </p>
          <ol class="m-0 flex list-none flex-col gap-1.5 p-0">
            <li v-for="sector in visibleSectors" :key="`${sector.lat}-${sector.lng}`">
              <button
                type="button"
                class="flex w-full gap-2 rounded-lg border border-[#e2dac9] bg-[#fffdf8] px-2 py-2 text-left"
                @click="emit('highlightPicked', { lat: sector.lat, lng: sector.lng })"
              >
                <span
                  class="flex h-5 min-w-10 items-center justify-center rounded-md px-1 text-[0.62rem] font-bold text-white"
                  :style="{ background: scoreColor(sector.maxScore) }"
                >
                  {{ sector.areaHa < 10 ? sector.areaHa.toFixed(1) : Math.round(sector.areaHa) }} ha
                </span>
                <span class="flex flex-col text-[0.78rem]">
                  <strong>{{ Math.round(sector.minScore) }}–{{ Math.round(sector.maxScore) }} · {{ sector.areaHa }} ha</strong>
                  <span class="text-xs text-secondary">{{ sector.elevation }} m · versant {{ sector.exposure }} · {{ sectorStand(sector) }}</span>
                  <span v-if="formatAccess(sector.accessMeters)" class="text-xs text-secondary">{{ formatAccess(sector.accessMeters) }}</span>
                </span>
              </button>
            </li>
          </ol>
          <button
            v-if="hiddenSectorCount > 0 && !showAllSectors"
            type="button"
            class="mt-2 w-full cursor-pointer border-none bg-transparent p-0 text-left text-xs font-semibold text-primary"
            @click="showAllSectors = true"
          >
            Voir les {{ hiddenSectorCount }} autres
          </button>
          <button
            v-else-if="showAllSectors && props.sectors.length > SECTORS_SHOWN"
            type="button"
            class="mt-2 w-full cursor-pointer border-none bg-transparent p-0 text-left text-xs font-semibold text-primary"
            @click="showAllSectors = false"
          >
            Réduire la liste
          </button>
        </template>
        <p v-else class="m-0 text-xs leading-snug text-secondary">
          Aucune tache à 90 à moins de 1,5 km d'un chemin joignable en voiture dans la vue.
          Décochez « Masquer les zones peu accessibles » pour voir les zones isolées.
        </p>
      </DisclosureSection>
    </ExpansionPanel.Group>
  </div>
</template>
