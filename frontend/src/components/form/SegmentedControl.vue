<script setup lang="ts" generic="T extends string | number | boolean">
/** Компактный переключатель вариантов: ширина по содержимому, а не на всю строку. */
const model = defineModel<T>({ required: true })
defineProps<{ options: { value: T; label: string }[] }>()
</script>

<template>
  <div role="radiogroup" class="inline-flex w-fit max-w-full flex-wrap gap-0.5 rounded-lg border bg-muted/40 p-0.5">
    <button
      v-for="o in options"
      :key="String(o.value)"
      type="button"
      role="radio"
      :aria-checked="model === o.value"
      class="h-8 rounded-md px-3 text-sm whitespace-nowrap transition-colors"
      :class="model === o.value ? 'bg-background font-medium text-foreground shadow-sm ring-1 ring-border dark:bg-input/60' : 'text-muted-foreground hover:text-foreground'"
      @click="model = o.value"
    >
      {{ o.label }}
    </button>
  </div>
</template>
