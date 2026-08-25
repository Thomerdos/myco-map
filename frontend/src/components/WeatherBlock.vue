<script setup lang="ts">
import { computed } from 'vue'
import type { Weather } from '../types'

const props = withDefaults(defineProps<{
  weather: Weather
  projectionDate: string
  projectionFrom: string
  atPoint: boolean
  coordinates?: { lat: number, lng: number } | null
  compact?: boolean
}>(), {
  compact: false,
})

const title = computed(() => {
  if (!props.projectionDate || props.projectionDate === props.projectionFrom) {
    return 'Conditions du moment'
  }
  const date = new Date(`${props.projectionDate}T12:00:00`)
  return `Conditions au ${date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })}`
})

const scope = computed(() => {
  if (props.atPoint && props.coordinates) {
    return `Au point ${props.coordinates.lat.toFixed(4)}°, ${props.coordinates.lng.toFixed(4)}°`
  }
  return 'Au centre de la vue — cliquez la carte pour le lieu exact'
})

const accumulationWindow = computed(() => dateWindowLabel(props.projectionDate, 26))
const recentWindow = computed(() => dateWindowLabel(props.projectionDate, 5))

function dateWindowLabel(endDate: string, days: number): string {
  if (!endDate) return `${days} j`
  const end = new Date(`${endDate}T12:00:00`)
  const start = new Date(end)
  start.setDate(start.getDate() - (days - 1))
  const fmt = (value: Date) => value.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
  return `du ${fmt(start)} au ${fmt(end)}`
}

function daysBeforeThisDate(days: number | null | undefined): string {
  if (days == null) return ''
  if (days === 0) return 'le jour même'
  if (days === 1) return 'la veille de cette date'
  return `${days} j avant cette date`
}

function stormDateLabel(days: number | null | undefined): string {
  if (days == null || !props.projectionDate) return daysBeforeThisDate(days)
  const date = new Date(`${props.projectionDate}T12:00:00`)
  date.setDate(date.getDate() - days)
  const stamp = date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
  if (days === 0) return `le ${stamp}`
  return `le ${stamp} (${daysBeforeThisDate(days)})`
}

function weatherTone(weather: Weather): string {
  if (weather.driedOutAfterSoaking) return 'bg-[#f3e6d2] text-[#7a4e12]'
  const days = weather.flushDaysSince ?? weather.daysSinceSoakingRain
  if (days === null) return 'bg-[#ece7dc] text-[#5a5346]'
  if (weather.flushPhase === 'incubating') return 'bg-[#f3e6d2] text-[#7a4e12]'
  if (weather.flushPhase === 'starting') return 'bg-[#ddecd8] text-[#1f5c32]'
  if (weather.flushPhase === 'peak') return 'bg-[#fde8d8] text-[#9a3412]'
  if (weather.flushPhase === 'declining' || weather.flushPhase === 'lingering') return 'bg-[#efe6c8] text-[#6a5310]'
  if (days <= 3) return 'bg-[#f3e6d2] text-[#7a4e12]'
  if (days <= 6) return 'bg-[#efe6c8] text-[#6a5310]'
  if (days <= 14) return 'bg-[#ddecd8] text-[#1f5c32]'
  return 'bg-[#ece7dc] text-[#5a5346]'
}
</script>

<template>
  <section>
    <template v-if="compact">
      <h3 class="mb-1 text-[0.74rem] font-bold uppercase tracking-wider text-secondary">{{ title }}</h3>
      <div class="rounded-lg px-2.5 py-2 text-[0.82rem] font-semibold leading-snug" :class="weatherTone(weather)">
        {{ weather.label }}
      </div>
      <p v-if="weather.degraded" class="mt-2 text-xs text-warning">
        Données météo indisponibles, valeurs de repli utilisées.
      </p>
    </template>
    <template v-else>
      <p class="mb-2 text-xs leading-snug text-secondary">{{ scope }}</p>
      <ul class="m-0 flex list-none flex-col gap-1.5 p-0 text-[0.81rem] leading-snug">
        <li v-if="weather.flushDaysSince != null">
          <strong>{{ weather.flushMillimetres }} mm</strong>
          d'orage suivi pour la pousse, tombés {{ stormDateLabel(weather.flushDaysSince) }}
        </li>
        <li v-else-if="weather.daysSinceSoakingRain != null">
          <strong>{{ weather.soakingRain }} mm</strong>
          de dernière pluie marquante, tombés {{ stormDateLabel(weather.daysSinceSoakingRain) }}
        </li>
        <li v-else>Pas d'orage assez franc (≥ 15 mm) dans les 15 j avant cette date.</li>
        <li>
          <strong>{{ weather.accumulatedRain }} mm</strong>
          tombés {{ accumulationWindow }}
          <span class="text-secondary"> — cumul 26 j, réservoir d'eau du mycélium</span>
        </li>
        <li>
          <strong>{{ weather.recentRain }} mm</strong>
          tombés {{ recentWindow }}
          <span class="text-secondary"> — pluie de litière récente</span>
        </li>
        <li v-if="weather.flushDaysSince != null && weather.rainSinceSoaking != null">
          <strong>{{ weather.rainSinceSoaking }} mm</strong>
          depuis l'orage suivi
          <span class="text-secondary"> — ce qui est tombé après l'épisode, pas le cumul 26 j</span>
        </li>
        <li v-if="weather.driedOutAfterSoaking" class="font-semibold text-[#8a5a12]">
          Assèchement trop rapide après l'épisode : la pousse est compromise.
        </li>
        <li>
          <strong>{{ weather.temperature }} °C</strong> ce jour-là ·
          <strong>{{ weather.humidity }} %</strong> d'humidité de l'air
        </li>
      </ul>
      <p v-if="weather.degraded" class="mt-2 text-xs text-warning">
        Données météo indisponibles, valeurs de repli utilisées.
      </p>
    </template>
  </section>
</template>
