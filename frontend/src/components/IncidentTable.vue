<script setup lang="ts">
import type { Incident } from '@/api/types'
import StatusBadge from '@/components/StatusBadge.vue'
import { formatDate, formatDuration } from '@/lib/format'

defineProps<{ items: Incident[]; showMonitor?: boolean }>()
</script>

<template>
  <div class="overflow-x-auto rounded-xl border">
    <table class="w-full text-sm">
      <thead class="border-b text-left text-xs text-muted-foreground">
        <tr>
          <th class="px-4 py-2 font-medium">Статус</th>
          <th v-if="showMonitor" class="px-4 py-2 font-medium">Монитор</th>
          <th class="px-4 py-2 font-medium">Начало</th>
          <th class="px-4 py-2 font-medium">Длительность</th>
          <th class="px-4 py-2 font-medium">Причина</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="i in items" :key="i.id" class="border-b last:border-0">
          <td class="px-4 py-2 whitespace-nowrap">
            <StatusBadge v-if="i.status === 'open'" tone="danger" label="Активный" />
            <StatusBadge v-else tone="success" label="Закрыт" />
          </td>
          <td v-if="showMonitor" class="px-4 py-2">
            <RouterLink v-if="i.monitor" :to="`/monitoring/${i.monitor.id}`" class="font-mono text-link hover:underline">{{ i.monitor.name }}</RouterLink>
            <span v-else>—</span>
          </td>
          <td class="px-4 py-2 whitespace-nowrap tabular-nums">{{ formatDate(i.started_at) }}</td>
          <td class="px-4 py-2 whitespace-nowrap tabular-nums">
            {{ formatDuration(i.duration_seconds) }}<span v-if="i.status === 'open'" class="text-muted-foreground"> и идёт</span>
          </td>
          <td class="max-w-md px-4 py-2 break-words text-muted-foreground">{{ i.reason || '—' }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
