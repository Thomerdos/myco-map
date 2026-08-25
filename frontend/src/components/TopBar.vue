<script setup lang="ts">
import { Button } from '@vuetify/v0'
import type { ProjectionDay, ScoreProjection } from '../types'
import { projectionMeterPercent, roundedScore, scoreInk } from '../lib/scoreColor'

defineProps<{
  areaName: string
  habitatOnly: boolean
  loadingLayer: boolean
  loadingProjection: boolean
  isMobile: boolean
  controlsOpen: boolean
  weatherLabel: string | null
  inSeason: boolean | null
  seasonGate: string | null
  projection: ScoreProjection | null
  projectionDate: string
}>()

const emit = defineEmits<{
  'update:projectionDate': [value: string]
  toggleControls: []
}>()

function dayTitle(day: ProjectionDay): string {
  const score = roundedScore(day.best)
  const scoreBit = score === null ? 'score indisponible' : `meilleur ${score}`
  return `${day.weather.label} — ${scoreBit}`
}
</script>

<template>
  <div
    class="pointer-events-auto relative shrink-0 rounded-xl border border-[#d9d0bc] bg-surface/95 shadow-lg backdrop-blur md:rounded-[14px]"
    :aria-busy="loadingLayer || loadingProjection"
  >
    <span v-if="loadingLayer || loadingProjection" class="sr-only">Calcul en cours</span>
    <div
      v-if="loadingLayer || loadingProjection"
      class="pointer-events-none absolute inset-x-0 bottom-0 h-0.5 overflow-hidden rounded-b-xl md:rounded-b-[14px]"
      aria-hidden="true"
    >
      <div class="bar-indeterminate h-full w-1/3 rounded-full bg-primary" />
    </div>
    <!-- Desktop: one row -->
    <div v-if="!isMobile" class="flex items-center gap-3 px-3.5 py-2">
      <div class="flex min-w-24 shrink-0 flex-col">
        <strong class="text-[1.1rem] leading-tight font-bold tracking-tight text-primary">Myco Map</strong>
        <span class="truncate text-xs text-secondary">{{ areaName }}</span>
      </div>

      <div
        v-if="!habitatOnly && (projection || loadingProjection)"
        class="flex min-w-0 flex-1 items-center gap-2"
      >
        <div class="flex min-w-0 flex-1 gap-0.5">
          <button
            v-for="day in projection?.days ?? []"
            :key="day.date"
            type="button"
            class="flex min-w-0 flex-1 flex-col items-center rounded-md px-0.5 py-1 text-on-surface outline-none transition-colors focus-visible:ring-2 focus-visible:ring-primary"
            :class="[
              day.date === projectionDate ? 'font-semibold' : 'hover:bg-[#efe8d8]',
              { 'opacity-55': !day.inSeason },
            ]"
            :aria-pressed="day.date === projectionDate"
            :title="dayTitle(day)"
            @click="emit('update:projectionDate', day.date)"
          >
            <span
              class="w-full truncate text-center text-[0.62rem] leading-none"
              :class="day.date === projectionDate ? 'text-primary' : 'text-secondary'"
            >{{ day.label }}</span>
            <strong
              class="mt-0.5 text-[0.82rem] leading-none tabular-nums"
              :style="{ color: scoreInk(day.best) }"
            >{{ roundedScore(day.best) ?? '—' }}</strong>
            <span class="mt-1 h-1 w-[80%] overflow-hidden rounded-full bg-[#e4dccb]">
              <span
                class="block h-full rounded-full"
                :style="{
                  width: `${projectionMeterPercent(day.best)}%`,
                  background: scoreInk(day.best),
                }"
              />
            </span>
          </button>
        </div>
      </div>

      <div class="flex min-w-0 shrink-0 items-center gap-1.5">
        <span v-if="habitatOnly" class="rounded-full bg-[#2f3d55] px-2.5 py-1 text-xs text-on-primary">Potentiel d'habitat</span>
        <span v-else-if="weatherLabel" class="max-w-72 truncate rounded-full bg-primary px-2.5 py-1 text-xs text-on-primary">{{ weatherLabel }}</span>
        <span
          v-if="inSeason !== null && !habitatOnly"
          class="rounded-full px-2.5 py-1 text-xs whitespace-nowrap"
          :class="inSeason ? 'bg-[#d6ecd9] text-success' : 'bg-[#c45c12] text-white'"
          :title="seasonGate ?? undefined"
        >
          {{ inSeason ? 'En saison' : 'Hors saison' }}
        </span>
      </div>
    </div>

    <!-- Mobile: title + filtres, then scrollable days -->
    <template v-else>
      <div class="flex items-center justify-between gap-2 px-2.5 py-2">
        <div class="flex min-w-0 flex-col">
          <strong class="text-base font-bold tracking-tight text-primary">Myco Map</strong>
          <span class="truncate text-[0.7rem] text-secondary">{{ areaName }}</span>
        </div>
        <Button.Root
          class="shrink-0 rounded-lg border border-[#cfc6b1] bg-[#fffdf8] px-2.5 py-1.5 text-sm"
          @click="emit('toggleControls')"
        >
          <Button.Content>{{ controlsOpen ? 'Fermer' : 'Filtres' }}</Button.Content>
        </Button.Root>
      </div>
      <p
        v-if="inSeason === false && !habitatOnly && seasonGate"
        class="border-t border-[#e4c9a0] bg-[#fbf3e4] px-2.5 py-1.5 text-[0.72rem] leading-snug text-[#8a5a12]"
      >
        {{ seasonGate }}
      </p>
      <div
        v-if="!habitatOnly && (projection || loadingProjection)"
        class="border-t border-[#d9d0bc] px-2.5 py-1.5"
      >
        <div class="flex gap-0.5 overflow-x-auto pb-0.5 [-webkit-overflow-scrolling:touch] snap-x snap-mandatory">
          <button
            v-for="day in projection?.days ?? []"
            :key="day.date"
            type="button"
            class="flex min-w-11 shrink-0 snap-start flex-col items-center rounded-md px-1 py-1 text-on-surface outline-none transition-colors focus-visible:ring-2 focus-visible:ring-primary"
            :class="[
              day.date === projectionDate ? 'font-semibold' : 'hover:bg-[#efe8d8]',
              { 'opacity-55': !day.inSeason },
            ]"
            :aria-pressed="day.date === projectionDate"
            :title="dayTitle(day)"
            @click="emit('update:projectionDate', day.date)"
          >
            <span
              class="text-[0.62rem] leading-none"
              :class="day.date === projectionDate ? 'text-primary' : 'text-secondary'"
            >{{ day.label }}</span>
            <strong
              class="mt-0.5 text-[0.82rem] leading-none tabular-nums"
              :style="{ color: scoreInk(day.best) }"
            >{{ roundedScore(day.best) ?? '—' }}</strong>
            <span class="mt-1 h-1 w-[80%] overflow-hidden rounded-full bg-[#e4dccb]">
              <span
                class="block h-full rounded-full"
                :style="{
                  width: `${projectionMeterPercent(day.best)}%`,
                  background: scoreInk(day.best),
                }"
              />
            </span>
          </button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.bar-indeterminate {
  animation: bar-slide 1.15s ease-in-out infinite;
}

@keyframes bar-slide {
  0% { transform: translateX(-120%); }
  100% { transform: translateX(420%); }
}
</style>
