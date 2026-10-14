<script setup lang="ts">
import { computed } from 'vue'

/** Мини-график без осей для списков: значения 0…max слева направо. */
const props = withDefaults(defineProps<{ values: number[]; max?: number; label: string }>(), { max: 100 })
const W = 120
const H = 28
const d = computed(() => {
  const n = props.values.length
  if (n < 2) return ''
  return props.values.map((v, i) => `${i ? 'L' : 'M'}${((i / (n - 1)) * W).toFixed(1)},${(H - 2 - (Math.min(v, props.max) / props.max) * (H - 4)).toFixed(1)}`).join('')
})
</script>

<template>
  <svg :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="none" class="h-7 w-full text-primary" role="img" :aria-label="label">
    <path v-if="d" :d="d" fill="none" stroke="currentColor" stroke-width="1.5" vector-effect="non-scaling-stroke" stroke-linejoin="round" />
  </svg>
</template>
