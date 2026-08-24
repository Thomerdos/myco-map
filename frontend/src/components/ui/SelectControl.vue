<script setup lang="ts">
export interface SelectOption {
  value: string
  label: string
}

export interface SelectGroup {
  label: string
  options: SelectOption[]
}

const model = defineModel<string>({ required: true })

defineProps<{
  options?: SelectOption[]
  groups?: SelectGroup[]
  ariaLabel?: string
}>()
</script>

<template>
  <select
    v-model="model"
    class="w-full cursor-pointer rounded-md border border-[#d4cbb7] bg-[#fffdf8] px-2 py-1.5 text-sm text-on-surface"
    :aria-label="ariaLabel"
  >
    <template v-if="groups?.length">
      <optgroup v-for="group in groups" :key="group.label" :label="group.label">
        <option v-for="option in group.options" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </optgroup>
    </template>
    <option v-else v-for="option in options" :key="option.value" :value="option.value">
      {{ option.label }}
    </option>
  </select>
</template>
