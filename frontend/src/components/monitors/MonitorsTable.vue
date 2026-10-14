<script setup lang="ts">
import { useRouter } from 'vue-router'
import { formatInterval, formatUptime, type Monitor, MONITOR_STATUS, monitorTarget } from '@/api/monitors'
import Icon from '@/components/Icon.vue'
import HeartbeatBar from '@/components/monitors/HeartbeatBar.vue'

withDefaults(defineProps<{ items: Monitor[]; emptyText?: string; showProject?: boolean }>(), { emptyText: 'Мониторов нет', showProject: true })
const router = useRouter()

/** Цвет аптайма: ниже 99% — заметно, ниже 95% — тревожно */
const uptimeClass = (v: number | null) => (v === null ? 'text-muted-foreground' : v < 95 ? 'text-destructive' : v < 99 ? 'text-amber-600 dark:text-amber-400' : '')
</script>

<template>
  <div class="overflow-x-auto rounded-xl border bg-card">
    <table class="w-full table-fixed text-sm">
      <thead class="border-b text-left text-muted-foreground">
        <tr>
          <th class="w-[55%] px-4 py-3 font-medium lg:w-[38%]">Монитор</th>
          <th class="hidden px-4 py-3 font-medium lg:table-cell">Последние проверки</th>
          <th class="w-28 px-4 py-3 text-right font-medium whitespace-nowrap">Аптайм 24 ч</th>
          <th class="w-24 px-4 py-3 text-right font-medium">Ответ</th>
          <th class="w-10 px-2" />
        </tr>
      </thead>
      <tbody class="divide-y">
        <tr v-for="m in items" :key="m.id" class="cursor-pointer hover:bg-accent/40" @click="router.push({ name: 'monitor', params: { id: m.id } })">
          <td class="px-4 py-3">
            <div class="flex items-center gap-2">
              <span class="size-2.5 shrink-0 rounded-full" :class="[MONITOR_STATUS[m.status].dot, m.status === 'down' && 'animate-pulse']" :title="MONITOR_STATUS[m.status].label" />
              <RouterLink :to="{ name: 'monitor', params: { id: m.id } }" class="truncate font-medium hover:underline" @click.stop>{{ m.name }}</RouterLink>
              <RouterLink v-if="showProject && m.project" :to="{ name: 'project', params: { id: m.project.id } }" class="shrink-0 rounded-md border px-1.5 py-0.5 text-xs text-muted-foreground hover:text-foreground" @click.stop>{{ m.project.name }}</RouterLink>
            </div>
            <div class="truncate pl-4.5 text-xs text-muted-foreground">
              <span class="font-mono">{{ monitorTarget(m) }}</span> · каждые {{ formatInterval(m.interval) }}
              <template v-if="m.status === 'down' && m.last_error"> · <span class="text-destructive">{{ m.last_error }}</span></template>
            </div>
          </td>
          <td class="hidden px-4 py-3 lg:table-cell">
            <HeartbeatBar :beats="m.recent" :slots="40" />
          </td>
          <td class="px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums" :class="uptimeClass(m.uptime.h24)">{{ formatUptime(m.uptime.h24) }}</td>
          <td class="px-4 py-3 text-right whitespace-nowrap text-muted-foreground tabular-nums">{{ m.last_response_ms !== null && m.status !== 'paused' ? `${m.last_response_ms} мс` : '—' }}</td>
          <td class="px-2 text-right whitespace-nowrap text-muted-foreground" @click.stop><slot name="actions" :item="m" /><Icon name="chevron-right" class="ml-1 inline size-4" /></td>
        </tr>
        <tr v-if="!items.length">
          <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">{{ emptyText }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
