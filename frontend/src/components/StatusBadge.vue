<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ tone: 'neutral' | 'info' | 'success' | 'warning' | 'danger'; label: string }>()
const cls = computed(
  () =>
    ({
      neutral: ['bg-muted text-muted-foreground', 'bg-muted-foreground'],
      info: ['bg-info/12', 'bg-info'],
      success: ['bg-success/12', 'bg-success'],
      warning: ['bg-warning/15', 'bg-warning'],
      danger: ['bg-destructive/12', 'bg-destructive'],
    })[props.tone],
)
</script>

<template>
  <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap" :class="cls[0]">
    <span class="size-1.5 rounded-full" :class="cls[1]" />{{ label }}
  </span>
</template>
