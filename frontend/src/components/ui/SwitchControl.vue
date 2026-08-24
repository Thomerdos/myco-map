<script setup lang="ts">
import { Switch } from '@vuetify/v0'

const model = defineModel<boolean>({ default: false })
</script>

<template>
  <label class="flex cursor-pointer items-start gap-2 text-sm text-on-surface">
    <Switch.Root v-model="model" class="mt-0.5 shrink-0">
      <Switch.Track class="relative block h-5 w-9 rounded-full bg-[#d4cbb7] transition-colors data-[state=checked]:bg-primary">
        <Switch.Thumb class="absolute top-0.5 left-0.5 block h-4 w-4 rounded-full bg-white shadow transition-transform data-[state=checked]:translate-x-4" />
      </Switch.Track>
    </Switch.Root>
    <span><slot /></span>
  </label>
</template>
