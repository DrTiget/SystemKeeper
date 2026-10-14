<script setup lang="ts">
import { usageTone } from '@/api/metrics'

/** Шкала заполненности: подпись, процент и подробности (например, «3.1 из 8 ГБ»). */
defineProps<{ label: string; value: number | null; detail?: string }>()
</script>

<template>
  <div class="grid gap-1">
    <div class="flex items-baseline justify-between gap-2 text-sm">
      <span class="truncate text-muted-foreground">{{ label }}</span>
      <span class="font-medium whitespace-nowrap tabular-nums" :class="value !== null && value >= 90 && 'text-destructive'">{{ value === null ? '—' : `${Math.round(value)}%` }}</span>
    </div>
    <div class="h-1.5 overflow-hidden rounded-full bg-muted" role="meter" :aria-label="label" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="value ?? undefined">
      <div class="h-full rounded-full transition-[width]" :class="usageTone(value)" :style="{ width: `${Math.min(100, value ?? 0)}%` }" />
    </div>
    <span v-if="detail" class="text-xs text-muted-foreground tabular-nums">{{ detail }}</span>
  </div>
</template>
