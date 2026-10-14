<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { api } from '@/api/client'
import { formatRate, METRICS_STATUS, pct, type ServerMetrics } from '@/api/metrics'
import Icon from '@/components/Icon.vue'
import Sparkline from '@/components/metrics/Sparkline.vue'
import UsageMeter from '@/components/metrics/UsageMeter.vue'
import { formatBytes, formatDate, inputClass } from '@/lib/ui'
import { useProjectsStore } from '@/stores/projects'

const items = ref<ServerMetrics[]>([])
const loaded = ref(false)
const loadError = ref('')
useProjectsStore().crumbs = []

let timer: ReturnType<typeof setTimeout> | undefined
async function load() {
  clearTimeout(timer)
  try {
    items.value = (await api<{ data: ServerMetrics[] }>('/metrics/servers')).data
    loadError.value = ''
  } catch {
    loadError.value = 'Не удалось загрузить показатели серверов'
  } finally {
    loaded.value = true
  }
  timer = setTimeout(load, 30_000)
}
onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))

const search = ref('')
/** Самые загруженные — первыми: проблемы видно сразу */
const worst = (m: ServerMetrics) => Math.max(m.cpu ?? 0, m.memory ? pct(m.memory.used, m.memory.total) : 0, ...m.disks.map((d) => pct(d.used, d.total)))
const sorted = computed(() => {
  const q = search.value.trim().toLowerCase()
  const order = { unreachable: 0, stale: 1, ok: 2, disabled: 3 }
  return items.value
    .filter((m) => !q || m.server.name.toLowerCase().includes(q) || m.server.host.includes(q) || m.server.project?.name.toLowerCase().includes(q))
    .sort((a, b) => order[a.status] - order[b.status] || worst(b) - worst(a))
})
const fullestDisk = (m: ServerMetrics) => [...m.disks].sort((a, b) => pct(b.used, b.total) - pct(a.used, a.total))[0]
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div class="space-y-1">
        <h2 class="text-xl font-semibold tracking-tight">Серверы</h2>
        <p class="text-sm text-muted-foreground">Загрузка процессора, памяти и дисков. Снимается по SSH с подключений серверов.</p>
      </div>
      <div class="relative w-full max-w-xs">
        <Icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
        <input v-model="search" placeholder="Сервер, адрес или проект" aria-label="Поиск серверов" :class="inputClass" class="pl-9" />
      </div>
    </header>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>
    <p v-if="!loaded" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>

    <div v-else-if="sorted.length" class="grid items-start gap-4 md:grid-cols-2 2xl:grid-cols-3">
      <RouterLink
        v-for="m in sorted"
        :key="m.server.id"
        :to="{ name: 'server-metrics', params: { id: m.server.id } }"
        class="grid content-start gap-4 rounded-xl border bg-card p-4 shadow-xs transition-colors hover:border-primary/50"
        :class="m.status === 'unreachable' && 'border-destructive/50'"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="grid min-w-0 gap-0.5">
            <span class="flex items-center gap-2 font-medium">
              <span class="size-2 shrink-0 rounded-full" :class="METRICS_STATUS[m.status].dot" :title="METRICS_STATUS[m.status].label" />
              <span class="truncate">{{ m.server.name }}</span>
            </span>
            <span class="truncate pl-4 text-xs text-muted-foreground">
              <template v-if="m.server.project">{{ m.server.project.name }} · </template><span class="font-mono">{{ m.server.host }}</span>
            </span>
          </div>
          <span v-if="m.collected_at" class="text-xs whitespace-nowrap text-muted-foreground" :title="`Данные от ${formatDate(m.collected_at)}`">{{ formatDate(m.collected_at).split(', ')[1] }}</span>
        </div>

        <p v-if="m.status === 'disabled'" class="text-sm text-muted-foreground">Сбор показателей выключен</p>
        <p v-else-if="m.status === 'unreachable'" class="text-sm break-words text-destructive">{{ m.error ?? 'Сервер не отвечает' }}</p>
        <template v-else>
          <div class="grid grid-cols-[minmax(0,1fr)_7rem] items-end gap-3">
            <UsageMeter label="CPU" :value="m.cpu" :detail="m.load ? `load ${m.load.map((l) => l.toFixed(2)).join(' / ')}` : undefined" />
            <Sparkline :values="m.spark.cpu" label="CPU за последний час" />
          </div>
          <div class="grid grid-cols-[minmax(0,1fr)_7rem] items-end gap-3">
            <UsageMeter label="Память" :value="m.memory ? pct(m.memory.used, m.memory.total) : null" :detail="m.memory ? `${formatBytes(m.memory.used)} из ${formatBytes(m.memory.total)}` : undefined" />
            <Sparkline :values="m.spark.memory" label="Память за последний час" />
          </div>
          <UsageMeter
            v-if="fullestDisk(m)"
            :label="m.disks.length > 1 ? `Диск ${fullestDisk(m)!.mount} — самый заполненный из ${m.disks.length}` : `Диск ${fullestDisk(m)!.mount}`"
            :value="pct(fullestDisk(m)!.used, fullestDisk(m)!.total)"
            :detail="`свободно ${formatBytes(fullestDisk(m)!.total - fullestDisk(m)!.used)} из ${formatBytes(fullestDisk(m)!.total)}`"
          />
          <div v-if="m.network" class="flex gap-4 text-xs text-muted-foreground">
            <span>↓ {{ formatRate(m.network.rx) }}</span><span>↑ {{ formatRate(m.network.tx) }}</span>
          </div>
        </template>
      </RouterLink>
    </div>

    <div v-else-if="!loadError" class="grid justify-items-center gap-2 rounded-xl border border-dashed p-12 text-center">
      <Icon name="cpu" class="size-8 text-muted-foreground" />
      <p class="font-medium">{{ items.length ? 'Под поиск ничего не подходит' : 'Серверов пока нет' }}</p>
      <p v-if="!items.length" class="max-w-md text-sm text-muted-foreground">Добавьте сервер в проекте — показатели начнут сниматься по его SSH-подключению.</p>
    </div>
  </div>
</template>
