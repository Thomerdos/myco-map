<script setup lang="ts">
import { Button } from '@vuetify/v0'
import type { ScoreProjection } from '../types'
import { scoreTone } from '../lib/scoreColor'

defineProps<{
  areaName: string
  habitatOnly: boolean
  loadingLayer: boolean
  loadingProjection: boolean
  isMobile: boolean
  controlsOpen: boolean
  weatherLabel: string | null
  inSeason: boolean | null
  projection: ScoreProjection | null
  projectionDate: string
}>()

const emit = defineEmits<{
  'update:projectionDate': [value: string]
  toggleControls: []
}>()
</script>

<template>
  <div class="pointer-events-auto shrink-0 rounded-xl border border-[#d9d0bc] bg-surface/95 shadow-lg backdrop-blur md:rounded-[14px]">
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
        <span v-if="loadingProjection" class="shrink-0 text-xs font-semibold text-warning">Calcul…</span>
        <div class="flex min-w-0 flex-1 gap-1">
          <button
            v-for="day in projection?.days ?? []"
            :key="day.date"
            type="button"
            class="flex min-w-0 flex-1 flex-col items-center gap-0.5 rounded-lg border border-[#cfc6b1] bg-[#fffdf8] px-0.5 py-0.5 text-on-surface"
            :class="[
              scoreTone(day.best),
              { 'outline outline-2 outline-offset-1 outline-primary font-bold': day.date === projectionDate },
              { 'opacity-55': !day.inSeason },
            ]"
            :title="day.weather.label"
            @click="emit('update:projectionDate', day.date)"
          >
            <span class="w-full truncate text-center text-[0.62rem] text-secondary">{{ day.label }}</span>
            <strong class="text-[0.8rem] tabular-nums">{{ day.best !== null ? Math.round(day.best) : '—' }}</strong>
          </button>
        </div>
      </div>

      <div class="flex shrink-0 items-center gap-1.5">
        <span v-if="loadingLayer" class="rounded-full bg-warning px-2.5 py-1 text-xs font-bold text-[#2b1c07]">Calcul…</span>
        <span v-else-if="habitatOnly" class="rounded-full bg-[#2f3d55] px-2.5 py-1 text-xs text-on-primary">Potentiel d'habitat</span>
        <span v-else-if="weatherLabel" class="max-w-48 truncate rounded-full bg-primary px-2.5 py-1 text-xs text-on-primary">{{ weatherLabel }}</span>
        <span
          v-if="inSeason !== null && !habitatOnly"
          class="rounded-full px-2.5 py-1 text-xs whitespace-nowrap"
          :class="inSeason ? 'bg-[#d6ecd9] text-success' : 'bg-[#f2e2c4] text-[#8a5a12]'"
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
      <div
        v-if="!habitatOnly && (projection || loadingProjection)"
        class="border-t border-[#d9d0bc] px-2.5 py-1.5"
      >
        <div class="flex gap-1 overflow-x-auto pb-0.5 [-webkit-overflow-scrolling:touch] snap-x snap-mandatory">
          <button
            v-for="day in projection?.days ?? []"
            :key="day.date"
            type="button"
            class="flex min-w-10 shrink-0 snap-start flex-col items-center gap-0.5 rounded-lg border border-[#cfc6b1] bg-[#fffdf8] px-1 py-1 text-on-surface"
            :class="[
              scoreTone(day.best),
              { 'outline outline-2 outline-offset-1 outline-primary font-bold': day.date === projectionDate },
              { 'opacity-55': !day.inSeason },
            ]"
            :title="day.weather.label"
            @click="emit('update:projectionDate', day.date)"
          >
            <span class="text-[0.62rem] text-secondary">{{ day.label }}</span>
            <strong class="text-[0.8rem] tabular-nums">{{ day.best !== null ? Math.round(day.best) : '—' }}</strong>
          </button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.tone-high {
  border-color: #c2600f;
  background: #f8ebe0;
}
.tone-mid {
  border-color: #6f4a78;
  background: #f1e8f3;
}
.tone-low {
  border-color: #4a4a7a;
  background: #e8e9f0;
}
.tone-poor,
.tone-none {
  opacity: 0.72;
}
</style>
