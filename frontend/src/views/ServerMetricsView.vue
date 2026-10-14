<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { type AlertKind, type AlertRule, ruleApplies } from '@/api/alerts'
import { api, ApiError } from '@/api/client'
import { formatRate, formatUptimeSec, type MetricPoint, METRICS_STATUS, pct, type ServerMetrics } from '@/api/metrics'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import TimeChart from '@/components/charts/TimeChart.vue'
import SegmentedControl from '@/components/form/SegmentedControl.vue'
import ToggleSwitch from '@/components/form/ToggleSwitch.vue'
import UsageMeter from '@/components/metrics/UsageMeter.vue'
import { btnClass, formatBytes, formatDate, inputClass } from '@/lib/ui'
import { useProjectsStore } from '@/stores/projects'

const route = useRoute()
const id = Number(route.params.id)

const m = ref<ServerMetrics | null>(null)
const loadError = ref('')
const projects = useProjectsStore()
watch(m, (v) => {
  if (!v) return
  const s = v.server
  projects.crumbs = [
    ...(s.project ? [{ label: s.project.name, to: `/projects/${s.project.id}` }] : [{ label: 'Серверы', to: '/metrics' }]),
    { label: s.name, to: `/servers/${s.id}` },
    { label: 'Мониторинг' },
  ]
})

type Period = '1h' | '24h' | '7d' | '30d'
const period = ref<Period>('24h')
const periodOptions: { value: Period; label: string }[] = [
  { value: '1h', label: '1 час' },
  { value: '24h', label: '24 часа' },
  { value: '7d', label: '7 дней' },
  { value: '30d', label: '30 дней' },
]
const PERIOD_MS: Record<Period, number> = { '1h': 3600_000, '24h': 86400_000, '7d': 7 * 86400_000, '30d': 30 * 86400_000 }
const points = ref<MetricPoint[]>([])
const range = ref({ from: '', to: '' })

async function loadHistory() {
  try {
    points.value = (await api<{ data: MetricPoint[] }>(`/servers/${id}/metrics/history?period=${period.value}`)).data
  } catch {
    points.value = []
  }
  range.value = { from: new Date(Date.now() - PERIOD_MS[period.value]).toISOString(), to: new Date().toISOString() }
}
watch(period, loadHistory)

// Правила алертов — пороги показываем на графиках
const rules = ref<AlertRule[]>([])
const thresholdsFor = (kind: AlertKind) =>
  m.value
    ? rules.value
        .filter((r) => r.kind === kind && r.threshold !== null && ruleApplies(r, 'server', id, m.value!.server.project?.id ?? null))
        .map((r) => ({ value: r.threshold!, label: `алерт: ${r.name}` }))
    : []

let timer: ReturnType<typeof setTimeout> | undefined
async function load() {
  clearTimeout(timer)
  try {
    m.value = (await api<{ data: ServerMetrics }>(`/servers/${id}/metrics`)).data
  } catch (e) {
    if (!m.value) loadError.value = e instanceof ApiError && e.status === 404 ? 'Сервер не найден' : 'Не удалось загрузить показатели'
    return
  }
  await loadHistory()
  timer = setTimeout(load, 30_000)
}
onMounted(async () => {
  await load()
  rules.value = await api<{ data: AlertRule[] }>('/alert-rules').then((r) => r.data).catch(() => [])
})
onBeforeUnmount(() => clearTimeout(timer))

// Ряды для графиков
const times = computed(() => points.value.map((p) => p.at))
const col = <K extends keyof MetricPoint>(k: K) => points.value.map((p) => p[k] as number | null)
/** Диски на графике — три самых заполненных: больше линий уже не различить */
const chartDisks = computed(() => {
  const mounts = [...new Set(points.value.flatMap((p) => Object.keys(p.disks)))]
  const lastOf = (mt: string) => [...points.value].reverse().find((p) => p.disks[mt] !== undefined)?.disks[mt] ?? 0
  return mounts.sort((a, b) => lastOf(b) - lastOf(a)).slice(0, 3)
})
const percent = (v: number) => `${Math.round(v)}%`

// Настройки сбора
const settingsOpen = ref(false)
const settings = reactive({ enabled: true, interval: 60, retention_days: 30, disks_exclude: '' })
const settingsError = ref('')
const savingSettings = ref(false)
function openSettings() {
  const s = m.value!.settings
  Object.assign(settings, { ...s, disks_exclude: s.disks_exclude.join(', ') })
  settingsError.value = ''
  settingsOpen.value = true
}
async function saveSettings() {
  savingSettings.value = true
  settingsError.value = ''
  try {
    m.value = (
      await api<{ data: ServerMetrics }>(`/servers/${id}/metrics/settings`, {
        method: 'PUT',
        body: { ...settings, disks_exclude: settings.disks_exclude.split(/[,\s]+/).filter(Boolean) },
      })
    ).data
    settingsOpen.value = false
  } catch (e) {
    settingsError.value = e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : 'Не удалось сохранить'
  } finally {
    savingSettings.value = false
  }
}

const card = 'grid gap-3 rounded-xl border bg-card p-4 shadow-xs'
const tile = 'grid content-start gap-1 rounded-xl border bg-card p-4 shadow-xs'
</script>

<template>
  <div class="flex max-w-7xl flex-col gap-6 p-4">
    <RouterLink :to="{ name: 'server', params: { id } }" class="inline-flex items-center gap-1.5 self-start text-sm text-muted-foreground hover:text-foreground">
      <Icon name="arrow-left" class="size-4" />{{ m?.server.name ?? 'Сервер' }}
    </RouterLink>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>
    <p v-else-if="!m" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>

    <template v-else>
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="grid gap-1">
          <h2 class="flex items-center gap-2 text-xl font-semibold tracking-tight"><Icon name="cpu" class="size-5 text-muted-foreground" />{{ m.server.name }}</h2>
          <p class="flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground">
            <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full" :class="METRICS_STATUS[m.status].dot" />{{ METRICS_STATUS[m.status].label }}</span>
            <template v-if="m.collected_at">· обновлено {{ formatDate(m.collected_at) }}</template>
            <template v-if="m.status !== 'disabled'">· каждые {{ m.settings.interval < 60 ? `${m.settings.interval} с` : `${m.settings.interval / 60} мин` }}</template>
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <SegmentedControl v-model="period" :options="periodOptions" />
          <button :class="btnClass.outline" @click="openSettings"><Icon name="sliders" />Сбор</button>
        </div>
      </header>

      <p v-if="m.status === 'unreachable' && m.error" class="rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive">{{ m.error }}</p>
      <div v-if="m.status === 'disabled'" class="flex flex-wrap items-center gap-3 rounded-lg border border-dashed px-4 py-3 text-sm">
        <span class="flex-1 text-muted-foreground">Сбор показателей выключен — ниже только история.</span>
        <button :class="btnClass.outline" @click="openSettings">Включить</button>
      </div>

      <!-- Сейчас -->
      <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div :class="tile">
          <UsageMeter label="CPU" :value="m.cpu" :detail="m.cpu_cores ? `ядер: ${m.cpu_cores}` : undefined" />
        </div>
        <div :class="tile">
          <UsageMeter label="Память" :value="m.memory ? pct(m.memory.used, m.memory.total) : null" :detail="m.memory ? `${formatBytes(m.memory.used)} из ${formatBytes(m.memory.total)}` : undefined" />
        </div>
        <div :class="tile">
          <span class="text-sm text-muted-foreground">Load average</span>
          <span class="text-lg font-semibold tabular-nums">{{ m.load ? m.load.map((l) => l.toFixed(2)).join(' · ') : '—' }}</span>
          <span class="text-xs text-muted-foreground">за 1 · 5 · 15 мин</span>
        </div>
        <div :class="tile">
          <span class="text-sm text-muted-foreground">Сеть</span>
          <span class="text-lg font-semibold tabular-nums">↓ {{ formatRate(m.network?.rx ?? null) }}</span>
          <span class="text-xs text-muted-foreground tabular-nums">↑ {{ formatRate(m.network?.tx ?? null) }}</span>
        </div>
      </div>

      <div class="grid gap-6 lg:grid-cols-2">
        <section :class="card">
          <h3 class="font-semibold">CPU</h3>
          <TimeChart :times="times" :series="[{ key: 'cpu', label: 'CPU', values: col('cpu') }]" :from="range.from" :to="range.to" :max="100" :format="percent" :thresholds="thresholdsFor('server_cpu')" />
        </section>
        <section :class="card">
          <h3 class="font-semibold">Память</h3>
          <TimeChart
            :times="times"
            :series="[
              { key: 'memory', label: 'Память', values: col('memory') },
              ...(m.swap?.total ? [{ key: 'swap', label: 'Swap', values: col('swap') }] : []),
            ]"
            :from="range.from"
            :to="range.to"
            :max="100"
            :format="percent"
            :thresholds="thresholdsFor('server_memory')"
          />
        </section>
        <section :class="card">
          <h3 class="font-semibold">Load average <span class="text-sm font-normal text-muted-foreground">за 1 мин</span></h3>
          <TimeChart :times="times" :series="[{ key: 'load', label: 'Load', values: col('load1') }]" :from="range.from" :to="range.to" :format="(v) => v.toFixed(v < 10 ? 1 : 0)" />
        </section>
        <section :class="card">
          <h3 class="font-semibold">Сеть</h3>
          <TimeChart
            :times="times"
            :series="[
              { key: 'rx', label: 'Входящий', values: col('rx') },
              { key: 'tx', label: 'Исходящий', values: col('tx') },
            ]"
            :from="range.from"
            :to="range.to"
            :format="formatRate"
          />
        </section>
      </div>

      <section :class="card">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <h3 class="font-semibold">Диски</h3>
          <span v-if="m.disks.length > 3" class="text-xs text-muted-foreground">на графике — три самых заполненных</span>
        </div>
        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
          <div class="grid gap-4">
            <UsageMeter
              v-for="d in m.disks"
              :key="d.mount"
              :label="`${d.mount}${d.device ? ` · ${d.device}` : ''}`"
              :value="pct(d.used, d.total)"
              :detail="`свободно ${formatBytes(d.total - d.used)} из ${formatBytes(d.total)}`"
            />
            <p v-if="!m.disks.length" class="text-sm text-muted-foreground">Данных о дисках нет</p>
          </div>
          <TimeChart
            :times="times"
            :series="chartDisks.map((mt) => ({ key: mt, label: mt, values: points.map((p) => p.disks[mt] ?? null) }))"
            :from="range.from"
            :to="range.to"
            :max="100"
            :format="percent"
            :thresholds="thresholdsFor('server_disk')"
          />
        </div>
      </section>

      <section :class="card">
        <h3 class="font-semibold">Система</h3>
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
          <div class="grid gap-0.5"><dt class="text-xs text-muted-foreground">ОС</dt><dd>{{ m.os ?? '—' }}</dd></div>
          <div class="grid gap-0.5"><dt class="text-xs text-muted-foreground">Работает без перезагрузки</dt><dd>{{ formatUptimeSec(m.uptime) }}</dd></div>
          <div class="grid gap-0.5">
            <dt class="text-xs text-muted-foreground">Swap</dt>
            <dd>{{ m.swap?.total ? `${formatBytes(m.swap.used)} из ${formatBytes(m.swap.total)}` : 'нет' }}</dd>
          </div>
        </dl>
      </section>
    </template>

    <Modal v-if="settingsOpen" title="Сбор показателей" @close="settingsOpen = false">
      <form class="grid gap-5" @submit.prevent="saveSettings">
        <ToggleSwitch v-model="settings.enabled" label="Снимать показатели" hint="По SSH-подключению сервера, без установки агента." />
        <template v-if="settings.enabled">
          <div class="flex flex-wrap items-center gap-2 text-sm">
            Каждые
            <input v-model.number="settings.interval" type="number" min="30" max="3600" required aria-label="Интервал, секунд" :class="inputClass" class="w-24!" />
            секунд
          </div>
          <div class="flex flex-wrap items-center gap-2 text-sm">
            Хранить историю
            <input v-model.number="settings.retention_days" type="number" min="1" max="365" required aria-label="Дней" :class="inputClass" class="w-20!" />
            дней
          </div>
          <div class="grid gap-1.5">
            <label for="ms-ex" class="text-sm font-medium">Не показывать диски</label>
            <input id="ms-ex" v-model="settings.disks_exclude" placeholder="/boot/efi, /snap/*" spellcheck="false" :class="inputClass" class="font-mono" />
            <p class="text-xs text-muted-foreground">Точки монтирования через запятую; tmpfs и overlay не показываются и так.</p>
          </div>
        </template>
        <p v-if="settingsError" class="text-sm text-destructive">{{ settingsError }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" :class="btnClass.outline" @click="settingsOpen = false">Отмена</button>
          <button type="submit" :class="btnClass.primary" :disabled="savingSettings">Сохранить</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
