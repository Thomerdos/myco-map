<script setup lang="ts">
import type { Highlight, LocationReport } from '../types'

const props = defineProps<{
  report: LocationReport | null
  highlights: Highlight[]
  loading: boolean
}>()

const emit = defineEmits<{
  highlightPicked: [point: { lat: number; lng: number }]
}>()

function barColor(value: number): string {
  if (value >= 78) return '#1c7a3e'
  if (value >= 62) return '#79b03a'
  if (value >= 45) return '#d8b845'
  if (value >= 25) return '#c98a3a'
  return '#a35347'
}
</script>

<template>
  <aside class="panel">
    <section v-if="props.report" class="block">
      <header class="score-header">
        <div class="score-value" :style="{ color: barColor(props.report.score) }">
          {{ Math.round(props.report.score) }}
          <span>/ 100</span>
        </div>
        <div class="score-meta">
          <strong>{{ props.report.levelLabel }}</strong>
          <span>{{ props.report.species.name }}</span>
        </div>
      </header>

      <p class="coords">
        {{ props.report.coordinates.lat.toFixed(4) }},
        {{ props.report.coordinates.lng.toFixed(4) }}
      </p>

      <h3 class="block-title">Ce que dit le terrain</h3>
      <dl class="terrain">
        <div><dt>Altitude</dt><dd>{{ props.report.terrain.elevation }} m</dd></div>
        <div><dt>Pente</dt><dd>{{ props.report.terrain.slope }}°</dd></div>
        <div><dt>Exposition</dt><dd>{{ props.report.terrain.exposure }}</dd></div>
        <div><dt>Couvert</dt><dd>{{ props.report.terrain.cover }}</dd></div>
        <div>
          <dt>Lisière</dt>
          <dd>
            {{ props.report.terrain.edgeDistance >= 0 ? `${props.report.terrain.edgeDistance} m dedans` : `${Math.abs(props.report.terrain.edgeDistance)} m dehors` }}
          </dd>
        </div>
        <div><dt>Eau</dt><dd>{{ props.report.terrain.waterDistance }} m</dd></div>
        <div><dt>Humidité</dt><dd>{{ props.report.terrain.moisture }} / 100</dd></div>
      </dl>

      <h3 class="block-title">Pourquoi ce score</h3>
      <ul class="criteria">
        <li v-for="criterion in props.report.breakdown" :key="criterion.criterion">
          <div class="criterion-head">
            <span>{{ criterion.label }}</span>
            <span class="criterion-weight">{{ Math.round(criterion.weight * 100) }} %</span>
          </div>
          <div class="criterion-track">
            <div
              class="criterion-fill"
              :style="{ width: `${criterion.value}%`, background: barColor(criterion.value) }"
            />
          </div>
          <p class="criterion-note">{{ criterion.explanation }}</p>
        </li>
      </ul>

      <h3 class="block-title">Autres espèces ici</h3>
      <ul class="other-species">
        <li v-for="item in props.report.allSpecies" :key="item.id">
          <span>{{ item.name }}</span>
          <span class="other-score" :style="{ color: barColor(item.score) }">
            {{ Math.round(item.score) }}
            <em v-if="!item.inSeason">hors saison</em>
          </span>
        </li>
      </ul>
    </section>

    <section v-else class="block empty">
      <h3 class="block-title">Aucun point sélectionné</h3>
      <p>
        Cliquez n'importe où sur la carte pour obtenir l'altitude, l'exposition, le couvert
        forestier et le détail du score à cet endroit.
      </p>
    </section>

    <section v-if="props.highlights.length > 0" class="block">
      <h3 class="block-title">Meilleurs secteurs visibles</h3>
      <ol class="highlights">
        <li v-for="(highlight, index) in props.highlights" :key="`${highlight.lat}-${highlight.lng}`">
          <button type="button" @click="emit('highlightPicked', { lat: highlight.lat, lng: highlight.lng })">
            <span class="rank">{{ index + 1 }}</span>
            <span class="highlight-body">
              <strong>{{ Math.round(highlight.score) }} / 100</strong>
              <span class="highlight-meta">
                {{ highlight.elevation }} m · versant {{ highlight.exposure }} · {{ highlight.cover }}
              </span>
              <span class="highlight-reason">{{ highlight.reasons[0] }}</span>
            </span>
          </button>
        </li>
      </ol>
    </section>
  </aside>
</template>

<style scoped>
.panel {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
  padding: 1rem 1.05rem 2rem;
  overflow-y: auto;
  background: #fbf9f3;
  border-left: 1px solid #ded6c4;
}

.block-title {
  margin: 1rem 0 0.5rem;
  font-size: 0.74rem;
  font-weight: 700;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: #6a6153;
}

.block-title:first-child {
  margin-top: 0;
}

.score-header {
  display: flex;
  align-items: baseline;
  gap: 0.7rem;
}

.score-value {
  font-size: 2.4rem;
  font-weight: 800;
  line-height: 1;
}

.score-value span {
  font-size: 0.85rem;
  font-weight: 600;
  color: #7d7463;
}

.score-meta {
  display: flex;
  flex-direction: column;
  font-size: 0.85rem;
  color: #4a443a;
}

.coords {
  margin: 0.35rem 0 0;
  font-size: 0.75rem;
  color: #8a8172;
  font-variant-numeric: tabular-nums;
}

.terrain {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.35rem 0.7rem;
  margin: 0;
  font-size: 0.8rem;
}

.terrain div {
  display: flex;
  justify-content: space-between;
  gap: 0.4rem;
  border-bottom: 1px dotted #ddd5c3;
  padding-bottom: 0.15rem;
}

.terrain dt {
  color: #7d7463;
}

.terrain dd {
  margin: 0;
  font-weight: 600;
  color: #2f2a22;
  text-align: right;
}

.criteria {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
}

.criterion-head {
  display: flex;
  justify-content: space-between;
  font-size: 0.79rem;
  color: #3f3a31;
}

.criterion-weight {
  color: #8a8172;
  font-size: 0.72rem;
}

.criterion-track {
  height: 6px;
  margin: 0.22rem 0 0.22rem;
  background: #e8e1d0;
  border-radius: 4px;
  overflow: hidden;
}

.criterion-fill {
  height: 100%;
  border-radius: 4px;
}

.criterion-note {
  margin: 0;
  font-size: 0.74rem;
  line-height: 1.35;
  color: #6a6153;
}

.other-species {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.81rem;
}

.other-species li {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
}

.other-score {
  font-weight: 700;
}

.other-score em {
  margin-left: 0.3rem;
  font-size: 0.68rem;
  font-weight: 500;
  font-style: normal;
  color: #8a8172;
}

.empty p {
  margin: 0;
  font-size: 0.82rem;
  line-height: 1.45;
  color: #6a6153;
}

.highlights {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  counter-reset: none;
}

.highlights button {
  display: flex;
  gap: 0.55rem;
  width: 100%;
  padding: 0.45rem 0.5rem;
  border: 1px solid #e2dac9;
  border-radius: 8px;
  background: #fffdf8;
  text-align: left;
  cursor: pointer;
}

.highlights button:hover {
  border-color: #14342a;
}

.rank {
  flex: 0 0 20px;
  height: 20px;
  border-radius: 50%;
  background: #14342a;
  color: #f4f1e6;
  font-size: 0.7rem;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}

.highlight-body {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  font-size: 0.78rem;
  color: #3f3a31;
}

.highlight-meta {
  color: #7d7463;
  font-size: 0.73rem;
}

.highlight-reason {
  color: #55503f;
  font-size: 0.72rem;
  line-height: 1.3;
}
</style>
