<script setup lang="ts">
import { computed } from 'vue'
import type { MetricsSettings, ServerMetric } from '@/api/types'
import { formatBytes } from '@/lib/format'
import { formatUptime, metricFlags, pct } from '@/lib/metrics'

const props = defineProps<{ metric: ServerMetric; settings: MetricsSettings; compact?: boolean }>()
const flags = computed(() => metricFlags(props.metric, props.settings))
const bad = 'text-destructive font-medium'
const barTone = (over: boolean, v: number) => (over ? 'bg-destructive' : v >= 70 ? 'bg-warning' : 'bg-chart-1')
const disks = computed(() => (props.compact ? props.metric.disks.slice(0, 3) : props.metric.disks))
</script>

<template>
  <div class="grid gap-2 text-sm">
    <div class="grid gap-1.5">
      <div v-for="d in disks" :key="d.mount" class="grid gap-0.5" :title="`${d.device}${d.fstype ? ' · ' + d.fstype : ''}`">
        <div class="flex justify-between gap-2 text-xs">
          <span class="truncate font-mono text-muted-foreground">{{ d.mount }}</span>
          <span class="shrink-0 tabular-nums" :class="flags.disk(d.used_percent) && bad">
            {{ formatBytes(d.used_bytes) }} / {{ formatBytes(d.total_bytes) }} · {{ pct(d.used_percent) }}
          </span>
        </div>
        <div class="h-1.5 rounded-full bg-muted">
          <div class="h-1.5 rounded-full" :class="barTone(flags.disk(d.used_percent), d.used_percent)" :style="{ width: `${Math.min(100, d.used_percent)}%` }" />
        </div>
      </div>
      <p v-if="compact && metric.disks.length > 3" class="text-xs text-muted-foreground">и ещё дисков: {{ metric.disks.length - 3 }}</p>
      <p v-if="!metric.disks.length" class="text-xs text-muted-foreground">Дисков не найдено.</p>
    </div>
    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-0.5 tabular-nums">
      <dt class="text-muted-foreground">Нагрузка</dt>
      <dd :class="flags.load && bad">
        {{ metric.load.per_core.toLocaleString('ru-RU', { maximumFractionDigits: 2 }) }} на ядро
        <span class="text-xs text-muted-foreground">(LA {{ metric.load.load1 }} / {{ metric.load.load5 }} / {{ metric.load.load15 }}, ядер {{ metric.cpu_count }})</span>
      </dd>
      <dt class="text-muted-foreground">Память</dt>
      <dd :class="flags.ram && bad">
        {{ pct(metric.memory.used_percent) }}
        <span class="text-xs text-muted-foreground">({{ formatBytes(metric.memory.used_bytes) }} из {{ formatBytes(metric.memory.total_bytes) }})</span>
      </dd>
      <dt class="text-muted-foreground">Swap</dt>
      <dd>
        <template v-if="metric.swap.total_bytes">{{ pct(metric.swap.used_percent) }} <span class="text-xs text-muted-foreground">({{ formatBytes(metric.swap.used_bytes) }} из {{ formatBytes(metric.swap.total_bytes) }})</span></template>
        <span v-else class="text-muted-foreground">нет</span>
      </dd>
      <dt class="text-muted-foreground">Аптайм</dt>
      <dd>{{ formatUptime(metric.uptime_seconds) }}</dd>
    </dl>
  </div>
</template>
