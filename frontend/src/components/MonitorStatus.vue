<script setup lang="ts">
import { computed } from 'vue'
import StatusBadge from '@/components/StatusBadge.vue'
import type { Monitor } from '@/api/types'

const props = defineProps<{ monitor: Pick<Monitor, 'enabled' | 'status'> }>()
const view = computed(() =>
  !props.monitor.enabled
    ? ({ tone: 'neutral', label: 'Пауза' } as const)
    : props.monitor.status === 'up'
      ? ({ tone: 'success', label: 'Работает' } as const)
      : props.monitor.status === 'down'
        ? ({ tone: 'danger', label: 'Недоступен' } as const)
        : ({ tone: 'info', label: 'Ожидает проверки' } as const),
)
</script>

<template><StatusBadge :tone="view.tone" :label="view.label" /></template>
