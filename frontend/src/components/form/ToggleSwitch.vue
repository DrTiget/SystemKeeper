<script setup lang="ts">
/** Переключатель «вкл/выкл» с подписью и пояснением. */
const model = defineModel<boolean>({ required: true })
defineProps<{ label: string; hint?: string }>()
</script>

<template>
  <label class="flex cursor-pointer items-start gap-3">
    <input v-model="model" type="checkbox" role="switch" class="peer sr-only" />
    <span
      class="relative mt-0.5 inline-flex h-5 w-9 shrink-0 rounded-full bg-input transition-colors peer-checked:bg-primary peer-focus-visible:ring-[3px] peer-focus-visible:ring-ring/50 after:absolute after:top-0.5 after:left-0.5 after:size-4 after:rounded-full after:bg-background after:shadow-xs after:transition-transform peer-checked:after:translate-x-4"
    />
    <span class="grid gap-0.5">
      <span class="text-sm font-medium">{{ label }}</span>
      <span v-if="hint || $slots.hint" class="text-sm text-muted-foreground"><slot name="hint">{{ hint }}</slot></span>
    </span>
  </label>
</template>
