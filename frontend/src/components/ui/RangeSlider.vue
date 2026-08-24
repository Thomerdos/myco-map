<script setup lang="ts">
import { Slider } from '@vuetify/v0'
import { computed } from 'vue'

const model = defineModel<number>({ required: true })

withDefaults(
  defineProps<{
    min?: number
    max?: number
    step?: number
  }>(),
  { min: 0, max: 1, step: 0.05 },
)

const values = computed({
  get: () => [model.value],
  set: (next: number[]) => {
    model.value = next[0] ?? model.value
  },
})
</script>

<template>
  <Slider.Root v-model="values" :min="min" :max="max" :step="step" class="relative flex h-6 items-center">
    <Slider.Track class="relative h-1.5 w-full rounded-full bg-[#e8e1d0]">
      <Slider.Range class="absolute inset-y-0 left-0 rounded-full bg-primary" />
    </Slider.Track>
    <Slider.Thumb class="h-4 w-4 rounded-full border-2 border-white bg-primary shadow" />
  </Slider.Root>
</template>
