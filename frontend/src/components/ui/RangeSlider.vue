<script setup lang="ts">
import { Slider } from '@vuetify/v0'

const model = defineModel<number>({ required: true })

withDefaults(
  defineProps<{
    min?: number
    max?: number
    step?: number
  }>(),
  { min: 0, max: 1, step: 0.05 },
)
</script>

<template>
  <Slider.Root
    v-model="model"
    :min="min"
    :max="max"
    :step="step"
    class="relative h-6 w-full select-none"
  >
    <Slider.Track class="absolute inset-x-0 top-1/2 h-1.5 w-full -translate-y-1/2 rounded-full bg-[#e8e1d0]">
      <Slider.Range class="absolute top-0 h-full rounded-full bg-primary" />
    </Slider.Track>
    <Slider.Thumb
      class="absolute top-1/2 z-1 h-4 w-4 -translate-x-1/2 -translate-y-1/2 cursor-pointer rounded-full border-2 border-white bg-primary shadow"
    />
  </Slider.Root>
</template>
