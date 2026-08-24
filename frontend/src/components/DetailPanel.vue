<script setup lang="ts">
import { Progress } from '@vuetify/v0'
import type { LocationReport, Sector } from '../types'
import { criterionColor, scoreColor } from '../lib/scoreColor'

const props = defineProps<{
  report: LocationReport | null
  sectors: Sector[]
  habitatOnly: boolean
  loading: boolean
}>()

const emit = defineEmits<{
  highlightPicked: [point: { lat: number; lng: number }]
}>()

function weatherTone(weather: LocationReport['weather']): string {
  const days = weather.flushDaysSince ?? weather.daysSinceSoakingRain
  if (days === null) return 'bg-[#ece7dc] text-[#5a5346]'
  if (weather.flushPhase === 'incubating') return 'bg-[#f3e6d2] text-[#7a4e12]'
  if (weather.flushPhase === 'starting' || weather.flushPhase === 'peak') return 'bg-[#ddecd8] text-[#1f5c32]'
  if (weather.flushPhase === 'declining' || weather.flushPhase === 'lingering') return 'bg-[#efe6c8] text-[#6a5310]'
  if (days <= 3) return 'bg-[#f3e6d2] text-[#7a4e12]'
  if (days <= 6) return 'bg-[#efe6c8] text-[#6a5310]'
  if (days <= 14) return 'bg-[#ddecd8] text-[#1f5c32]'
  return 'bg-[#ece7dc] text-[#5a5346]'
}

function sectorStand(sector: Sector): string {
  const parts = [sector.hostTreeCode ? sector.hostTree : sector.cover]
  if (sector.canopyCode) parts.push(sector.canopy.toLowerCase())
  return parts.join(', ')
}
</script>

<template>
  <div class="flex flex-col gap-4 bg-surface px-4 py-4">
    <div v-if="props.loading" class="flex justify-center py-8 text-sm text-secondary">Chargement…</div>

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

      <div v-if="!props.habitatOnly" class="mt-3 flex flex-col gap-0.5 rounded-lg px-2.5 py-2 text-[0.78rem] leading-snug" :class="weatherTone(props.report.weather)">
        <strong>{{ props.report.weather.label }}</strong>
        <span v-if="props.report.weather.flushDaysSince != null">
          Poussée suivie : {{ props.report.weather.flushMillimetres }}&nbsp;mm il y a {{ props.report.weather.flushDaysSince }}&nbsp;j
        </span>
        <span
          v-if="
            props.report.weather.daysSinceSoakingRain != null &&
            props.report.weather.flushDaysSince != null &&
            props.report.weather.daysSinceSoakingRain !== props.report.weather.flushDaysSince
          "
        >
          Orage plus récent : {{ props.report.weather.soakingRain }}&nbsp;mm il y a {{ props.report.weather.daysSinceSoakingRain }}&nbsp;j (pas encore productif)
        </span>
        <span v-else-if="props.report.weather.flushDaysSince == null && props.report.weather.daysSinceSoakingRain != null">
          Dernière pluie marquante : {{ props.report.weather.soakingRain }}&nbsp;mm il y a {{ props.report.weather.daysSinceSoakingRain }}&nbsp;j
        </span>
        <span v-else-if="props.report.weather.flushDaysSince == null">Pas d'épisode déclenchant clair sur 15&nbsp;j</span>
      </div>
      <p v-else class="mt-3 rounded-lg bg-[#e8ecf2] px-2.5 py-2 text-[0.78rem] text-[#2f3d55]">
        Mode potentiel d'habitat — météo et saison ignorées, poids renormalisés.
      </p>

      <h3 class="mb-2 mt-4 text-[0.74rem] font-bold uppercase tracking-wider text-secondary">Ce que dit le terrain</h3>
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
        <div class="flex justify-between gap-2 border-b border-dotted border-[#ddd5c3] pb-0.5"><dt class="text-secondary">Eau</dt><dd class="m-0 font-semibold">{{ props.report.terrain.waterDistance }} m</dd></div>
        <div class="flex justify-between gap-2 border-b border-dotted border-[#ddd5c3] pb-0.5"><dt class="text-secondary">Humidité</dt><dd class="m-0 font-semibold">{{ props.report.terrain.moisture }} / 100</dd></div>
        <div class="flex justify-between gap-2 border-b border-dotted border-[#ddd5c3] pb-0.5"><dt class="text-secondary">Géologie</dt><dd class="m-0 font-semibold">{{ props.report.terrain.geology }}</dd></div>
      </dl>

      <h3 class="mb-2 mt-4 text-[0.74rem] font-bold uppercase tracking-wider text-secondary">Pourquoi ce score</h3>
      <p class="mb-2 text-xs leading-snug text-secondary">
        Chaque barre est le score du critère sur ce point. Le pourcentage est son poids dans le modèle.
      </p>
      <ul class="m-0 flex list-none flex-col gap-3 p-0">
        <li v-for="criterion in props.report.breakdown" :key="criterion.criterion">
          <div class="mb-1 flex justify-between text-[0.79rem]">
            <span>{{ criterion.label }}</span>
            <span class="text-xs text-secondary">poids {{ Math.round(criterion.weight * 100) }} %</span>
          </div>
          <Progress.Root :model-value="criterion.value" :max="100">
            <Progress.Track class="h-1.5 overflow-hidden rounded bg-[#e8e1d0]">
              <Progress.Fill class="h-full rounded" :style="{ background: criterionColor(criterion.value) }" />
            </Progress.Track>
          </Progress.Root>
          <p class="mt-1 text-xs italic text-secondary">{{ criterion.rationale }}</p>
          <p class="m-0 text-xs text-secondary">{{ criterion.explanation }}</p>
        </li>
      </ul>

      <h3 class="mb-2 mt-4 text-[0.74rem] font-bold uppercase tracking-wider text-secondary">Autres espèces ici</h3>
      <ul class="m-0 flex list-none flex-col gap-1 p-0 text-[0.81rem]">
        <li v-for="item in props.report.allSpecies" :key="item.id" class="flex justify-between gap-2">
          <span>{{ item.name }}</span>
          <span class="font-bold" :style="{ color: scoreColor(item.score) }">
            {{ Math.round(item.score) }}
            <em v-if="!props.habitatOnly && !item.inSeason" class="ml-1 text-[0.68rem] font-normal not-italic text-secondary">hors saison</em>
          </span>
        </li>
      </ul>
    </section>

    <section v-else class="text-sm leading-snug text-secondary">
      <h3 class="mb-2 text-[0.74rem] font-bold uppercase tracking-wider">Aucun point sélectionné</h3>
      <p class="m-0">
        Cliquez n'importe où sur la carte pour obtenir l'altitude, l'exposition, le couvert forestier et le détail du score.
      </p>
    </section>

    <section v-if="props.sectors.length > 0">
      <h3 class="mb-1 text-[0.74rem] font-bold uppercase tracking-wider text-secondary">Secteurs à 90 ou plus</h3>
      <p class="mb-2 text-xs leading-snug text-secondary">
        Taches continues dans la vue actuelle, classées par surface.
      </p>
      <ol class="m-0 flex list-none flex-col gap-1.5 p-0">
        <li v-for="sector in props.sectors" :key="`${sector.lat}-${sector.lng}`">
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
            </span>
          </button>
        </li>
      </ol>
    </section>
  </div>
</template>
