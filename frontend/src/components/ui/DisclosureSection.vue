<script setup lang="ts">
import { ExpansionPanel } from '@vuetify/v0'

defineProps<{
  title: string
  preview?: string
  panel: string
}>()
</script>

<template>
  <ExpansionPanel.Root :value="panel" class="overflow-hidden rounded-lg border border-[#d9d0bc]">
    <ExpansionPanel.Header class="m-0">
      <ExpansionPanel.Activator
        class="flex w-full cursor-pointer items-start gap-2 border-none bg-[#fffdf8] px-2.5 py-2 text-left text-on-surface"
      >
        <span class="min-w-0 flex-1">
          <span class="block text-[0.74rem] font-bold uppercase tracking-wider text-secondary">
            {{ title }}
          </span>
          <span
            v-if="preview"
            class="mt-0.5 block truncate text-xs leading-snug text-secondary"
          >
            {{ preview }}
          </span>
        </span>
        <ExpansionPanel.Cue
          class="mt-0.5 inline-flex shrink-0 text-secondary transition-transform duration-200 data-[state=open]:rotate-180"
        >
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M6 9l6 6 6-6" />
          </svg>
        </ExpansionPanel.Cue>
      </ExpansionPanel.Activator>
    </ExpansionPanel.Header>
    <ExpansionPanel.Content class="border-t border-[#e8e1d0] bg-surface px-2.5 py-2">
      <slot />
    </ExpansionPanel.Content>
  </ExpansionPanel.Root>
</template>
