<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { type Alert, type Severity } from '@/api/alerts'
import { api, ApiError } from '@/api/client'
import Icon from '@/components/Icon.vue'
import AlertList from '@/components/alerts/AlertList.vue'
import AlertsTabs from '@/components/alerts/AlertsTabs.vue'
import SegmentedControl from '@/components/form/SegmentedControl.vue'
import ProjectSelect from '@/components/ProjectSelect.vue'
import { btnClass } from '@/lib/ui'
import { useProjectsStore } from '@/stores/projects'

useProjectsStore().crumbs = []

const status = ref<'firing' | 'resolved'>('firing')
const severity = ref<'all' | Severity>('all')
const projectId = ref<number | null>(null)
const items = ref<Alert[]>([])
const meta = ref<{ current_page: number; last_page: number; total: number } | null>(null)
const page = ref(1)
const loaded = ref(false)
const loadError = ref('')
const summary = ref<{ firing: number; critical: number } | null>(null)

let timer: ReturnType<typeof setTimeout> | undefined
async function load() {
  clearTimeout(timer)
  const qs = new URLSearchParams({ status: status.value, per_page: '30', page: String(page.value) })
  if (severity.value !== 'all') qs.set('severity', severity.value)
  if (projectId.value) qs.set('project_id', String(projectId.value))
  try {
    const [res, sum] = await Promise.all([
      api<{ data: Alert[]; meta?: typeof meta.value }>(`/alerts?${qs}`),
      api<{ data: { firing: number; critical: number } }>('/alerts/summary').catch(() => null),
    ])
    items.value = res.data
    meta.value = res.meta ?? null
    summary.value = sum?.data ?? null
    loadError.value = ''
  } catch {
    loadError.value = 'Не удалось загрузить алерты'
  } finally {
    loaded.value = true
  }
  if (status.value === 'firing') timer = setTimeout(load, 30_000)
}
watch([status, severity, projectId], () => {
  page.value = 1
  load()
})
onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))

async function ack(a: Alert) {
  try {
    const res = (await api<{ data: Alert }>(`/alerts/${a.id}/ack`, { method: 'POST' })).data
    items.value = items.value.map((x) => (x.id === a.id ? res : x))
  } catch (e) {
    loadError.value = e instanceof ApiError ? e.message : 'Не удалось отметить'
  }
}

const statusOptions = computed(() => [
  { value: 'firing' as const, label: summary.value ? `Активные ${summary.value.firing}` : 'Активные' },
  { value: 'resolved' as const, label: 'История' },
])
const severityOptions = [
  { value: 'all' as const, label: 'Все' },
  { value: 'critical' as const, label: 'Критичные' },
  { value: 'warning' as const, label: 'Предупреждения' },
]
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="space-y-1">
      <h2 class="text-xl font-semibold tracking-tight">Алерты</h2>
      <p class="text-sm text-muted-foreground">Что сломалось сейчас и что ломалось раньше — по правилам для серверов, аптайма и бекапов.</p>
    </header>
    <AlertsTabs />

    <div class="flex flex-wrap items-center gap-3">
      <SegmentedControl v-model="status" :options="statusOptions" />
      <SegmentedControl v-model="severity" :options="severityOptions" />
      <ProjectSelect v-model="projectId" class="w-56" none-label="Все проекты" />
    </div>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>
    <p v-if="!loaded" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>
    <template v-else-if="items.length">
      <AlertList :items="items" @ack="ack" />
      <div v-if="meta && meta.last_page > 1" class="flex items-center justify-center gap-3 text-sm">
        <button :class="btnClass.outline" :disabled="page <= 1" @click="(page--, load())">Назад</button>
        <span class="text-muted-foreground">{{ page }} из {{ meta.last_page }}</span>
        <button :class="btnClass.outline" :disabled="page >= meta.last_page" @click="(page++, load())">Дальше</button>
      </div>
    </template>
    <div v-else-if="!loadError" class="grid justify-items-center gap-2 rounded-xl border border-dashed p-12 text-center">
      <Icon :name="status === 'firing' ? 'check' : 'bell'" class="size-8" :class="status === 'firing' ? 'text-primary' : 'text-muted-foreground'" />
      <p class="font-medium">{{ status === 'firing' ? 'Всё работает' : 'Срабатываний ещё не было' }}</p>
      <p class="max-w-md text-sm text-muted-foreground">
        Алерты появляются по <RouterLink to="/alerts/rules" class="text-primary hover:underline">правилам</RouterLink>; уведомления уходят в <RouterLink to="/alerts/channels" class="text-primary hover:underline">каналы</RouterLink>.
      </p>
    </div>
  </div>
</template>
