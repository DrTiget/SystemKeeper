<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { api } from '@/api/client'
import { formatUptime, type Monitor, MONITOR_STATUS, type MonitorStatus, monitorTarget } from '@/api/monitors'
import Icon from '@/components/Icon.vue'
import SegmentedControl from '@/components/form/SegmentedControl.vue'
import MonitorsTable from '@/components/monitors/MonitorsTable.vue'
import { btnClass, inputClass } from '@/lib/ui'
import { useProjectsStore } from '@/stores/projects'

const monitors = ref<Monitor[]>([])
const loaded = ref(false)
const loadError = ref('')
const projects = useProjectsStore()
projects.crumbs = []

let timer: ReturnType<typeof setTimeout> | undefined
async function load() {
  clearTimeout(timer)
  try {
    monitors.value = (await api<{ data: Monitor[] }>('/monitors?per_page=500')).data
    loadError.value = ''
  } catch {
    loadError.value = 'Не удалось загрузить мониторы'
  } finally {
    loaded.value = true
  }
  // статусы меняются сами — обновляем список
  timer = setTimeout(load, 30_000)
}
onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))

const count = (s: MonitorStatus) => monitors.value.filter((m) => m.status === s).length
const avgUptime = computed(() => {
  const v = monitors.value.map((m) => m.uptime.h24).filter((u): u is number => u !== null)
  return v.length ? v.reduce((a, b) => a + b, 0) / v.length : null
})

// Фильтры
const search = ref('')
const status = ref<'all' | MonitorStatus>('all')
const statusOptions = computed(() => [
  { value: 'all' as const, label: `Все ${monitors.value.length}` },
  { value: 'down' as const, label: `Недоступны ${count('down')}` },
  { value: 'up' as const, label: `Работают ${count('up')}` },
  { value: 'paused' as const, label: `На паузе ${count('paused')}` },
])

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  return monitors.value.filter(
    (m) => (status.value === 'all' || m.status === status.value) && (!q || m.name.toLowerCase().includes(q) || monitorTarget(m).toLowerCase().includes(q)),
  )
})

/** Группы по проектам — в порядке меню; сначала группы, где что-то недоступно */
const groups = computed(() => {
  const order = projects.grouped.flatMap((g) => g.projects.map((p) => p.id))
  const map = new Map<number | null, { project: Monitor['project']; items: Monitor[] }>()
  for (const m of filtered.value) {
    const key = m.project?.id ?? null
    if (!map.has(key)) map.set(key, { project: m.project, items: [] })
    map.get(key)!.items.push(m)
  }
  const rank = (k: number | null) => (k === null ? Infinity : order.indexOf(k) === -1 ? order.length : order.indexOf(k))
  const statusRank: Record<MonitorStatus, number> = { down: 0, pending: 1, up: 2, paused: 3 }
  return [...map.entries()]
    .map(([k, g]) => ({ key: k, ...g, items: g.items.sort((a, b) => statusRank[a.status] - statusRank[b.status] || a.name.localeCompare(b.name)), down: g.items.some((m) => m.status === 'down') }))
    .sort((a, b) => Number(b.down) - Number(a.down) || rank(a.key) - rank(b.key))
})

const tile = 'grid gap-1 rounded-xl border bg-card p-4 shadow-xs'
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div class="space-y-1">
        <h2 class="text-xl font-semibold tracking-tight">Аптайм</h2>
        <p class="text-sm text-muted-foreground">Доступность сайтов, API и сервисов всех проектов.</p>
      </div>
      <RouterLink :to="{ name: 'monitor-new' }" :class="btnClass.primary"><Icon name="plus" />Новый монитор</RouterLink>
    </header>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>
    <p v-if="!loaded" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>

    <template v-else-if="monitors.length">
      <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <button :class="tile" class="text-left hover:border-primary/50" @click="status = 'up'">
          <span class="flex items-center gap-1.5 text-sm text-muted-foreground"><span class="size-2 rounded-full" :class="MONITOR_STATUS.up.dot" />Работают</span>
          <span class="text-2xl font-semibold tabular-nums">{{ count('up') }}</span>
        </button>
        <button :class="tile" class="text-left hover:border-primary/50" @click="status = 'down'">
          <span class="flex items-center gap-1.5 text-sm text-muted-foreground"><span class="size-2 rounded-full" :class="MONITOR_STATUS.down.dot" />Недоступны</span>
          <span class="text-2xl font-semibold tabular-nums" :class="count('down') > 0 && 'text-destructive'">{{ count('down') }}</span>
        </button>
        <button :class="tile" class="text-left hover:border-primary/50" @click="status = 'paused'">
          <span class="flex items-center gap-1.5 text-sm text-muted-foreground"><span class="size-2 rounded-full" :class="MONITOR_STATUS.paused.dot" />На паузе</span>
          <span class="text-2xl font-semibold tabular-nums">{{ count('paused') }}</span>
        </button>
        <div :class="tile">
          <span class="text-sm text-muted-foreground">Средний аптайм за 24 ч</span>
          <span class="text-2xl font-semibold tabular-nums">{{ formatUptime(avgUptime) }}</span>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-3">
        <div class="relative w-full max-w-xs">
          <Icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
          <input v-model="search" placeholder="Название или адрес" aria-label="Поиск мониторов" :class="inputClass" class="pl-9" />
        </div>
        <SegmentedControl v-model="status" :options="statusOptions" />
      </div>

      <section v-for="g in groups" :key="g.key ?? 'none'" class="grid gap-3">
        <h3 class="flex items-center gap-2 font-semibold">
          <Icon name="folder" class="size-4 text-muted-foreground" />
          <RouterLink v-if="g.project" :to="{ name: 'project', params: { id: g.project.id } }" class="hover:underline">{{ g.project.name }}</RouterLink>
          <span v-else>Без проекта</span>
          <span class="font-normal text-muted-foreground">{{ g.items.length }}</span>
        </h3>
        <MonitorsTable :items="g.items" :show-project="false" />
      </section>
      <p v-if="!groups.length" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">Под фильтр ничего не подходит</p>
    </template>

    <div v-else-if="!loadError" class="grid justify-items-center gap-3 rounded-xl border border-dashed p-12 text-center">
      <Icon name="activity" class="size-8 text-muted-foreground" />
      <div class="grid gap-1">
        <p class="font-medium">Мониторов пока нет</p>
        <p class="max-w-md text-sm text-muted-foreground">Добавьте сайт, API, порт или хост — SystemKeeper будет проверять их по расписанию и покажет, когда что-то упало.</p>
      </div>
      <RouterLink :to="{ name: 'monitor-new' }" :class="btnClass.primary"><Icon name="plus" />Новый монитор</RouterLink>
    </div>
  </div>
</template>
