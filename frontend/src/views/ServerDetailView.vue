<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api, ApiError } from '@/api/client'
import type { MetricsHistory, MetricsLatest, Server } from '@/api/types'
import Icon from '@/components/Icon.vue'
import ServerMetricsSummary from '@/components/ServerMetricsSummary.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import TimeChart, { type ChartSeries } from '@/components/TimeChart.vue'
import { btnClass, errorMessage, formatDate } from '@/lib/format'

const route = useRoute()
const id = computed(() => Number(route.params.id))

const server = ref<Server | null>(null)
const latest = ref<MetricsLatest | null>(null)
const history = ref<MetricsHistory | null>(null)
const loading = ref(true)
const notFound = ref(false)
const error = ref('')

const RANGES = [
  { key: '1h', label: '1 час', ms: 3600_000 },
  { key: '24h', label: '24 часа', ms: 86400_000 },
  { key: '7d', label: '7 дней', ms: 7 * 86400_000 },
] as const
const range = ref<(typeof RANGES)[number]['key']>('24h')
const rangeMs = computed(() => RANGES.find((r) => r.key === range.value)!.ms)
const window = ref({ from: 0, to: 0 })
const histLoading = ref(false)
const histError = ref('')

async function loadHistory() {
  histLoading.value = true
  histError.value = ''
  const to = Date.now()
  const from = to - rangeMs.value
  try {
    const res = await api<{ data: MetricsHistory }>(`/servers/${id.value}/metrics?from=${encodeURIComponent(new Date(from).toISOString())}&to=${encodeURIComponent(new Date(to).toISOString())}&points=200`)
    history.value = res.data
    window.value = { from, to }
  } catch (e) {
    histError.value = errorMessage(e, 'Не удалось загрузить историю метрик')
  } finally {
    histLoading.value = false
  }
}

async function loadLatest() {
  latest.value = (await api<{ data: MetricsLatest }>(`/servers/${id.value}/metrics/latest`)).data
}

async function load() {
  loading.value = true
  error.value = ''
  notFound.value = false
  try {
    server.value = (await api<{ data: Server }>(`/servers/${id.value}`)).data
    await Promise.all([loadLatest(), loadHistory()])
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) notFound.value = true
    else error.value = errorMessage(e, 'Не удалось загрузить сервер')
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(id, load)
watch(range, loadHistory)

// --- сбор «сейчас» ---
const collecting = ref(false)
const collectMsg = ref<{ ok: boolean; text: string } | null>(null)
async function collect() {
  collecting.value = true
  collectMsg.value = null
  try {
    const res = await api<{ data: { status: 'ok' | 'failed'; error: string | null } }>(`/servers/${id.value}/metrics/collect`, { method: 'POST' })
    collectMsg.value = res.data.status === 'ok' ? { ok: true, text: 'Метрики собраны' } : { ok: false, text: res.data.error ?? 'Не удалось собрать метрики' }
    server.value = (await api<{ data: Server }>(`/servers/${id.value}`)).data
    await Promise.all([loadLatest(), loadHistory()])
  } catch (e) {
    collectMsg.value = { ok: false, text: errorMessage(e, 'Не удалось собрать метрики') }
  } finally {
    collecting.value = false
  }
}

// --- графики ---
const gapMs = computed(() => (history.value ? history.value.bucket_seconds * 2.5 * 1000 : undefined))
const pts = computed(() => history.value?.points ?? [])
const t = (iso: string) => new Date(iso).getTime()
const loadSeries = computed<ChartSeries[]>(() => [{ name: 'Нагрузка на ядро', points: pts.value.map((p) => ({ t: t(p.time), v: p.load_per_core.avg })) }])
const ramSeries = computed<ChartSeries[]>(() => {
  const s: ChartSeries[] = [{ name: 'RAM', points: pts.value.map((p) => ({ t: t(p.time), v: p.mem_used_percent.avg })) }]
  if (pts.value.some((p) => p.swap_used_percent)) s.push({ name: 'Swap', points: pts.value.map((p) => ({ t: t(p.time), v: p.swap_used_percent?.avg ?? null })) })
  return s
})
const diskSeries = computed<ChartSeries[]>(() => {
  const mounts = [...new Set(pts.value.flatMap((p) => p.disks.map((d) => d.mount)))]
  return mounts.slice(0, 5).map((m) => ({
    name: m,
    points: pts.value.map((p) => ({ t: t(p.time), v: p.disks.find((d) => d.mount === m)?.used_percent.avg ?? null })),
  }))
})
const th = computed(() => server.value?.metrics_settings.thresholds)

const alertLabel = (a: MetricsLatest['alerts'][number]) =>
  a.metric === 'disk' ? `Диск ${a.target}: ${a.value}% (порог ${a.threshold}%)` : a.metric === 'ram' ? `RAM: ${a.value}% (порог ${a.threshold}%)` : `Нагрузка: ${a.value} на ядро (порог ${a.threshold})`
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <RouterLink to="/servers" class="text-sm text-link hover:underline">← Все серверы</RouterLink>

    <div v-if="loading" class="rounded-xl border p-10 text-center text-sm text-muted-foreground">Загрузка…</div>
    <div v-else-if="notFound" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">Сервер не найден — возможно, он удалён.</div>
    <div v-else-if="error" role="alert" class="flex items-center gap-3 rounded-lg border bg-card px-4 py-3 text-sm text-destructive">
      {{ error }}<button :class="[btnClass.outlineSm, 'ml-auto']" @click="load">Повторить</button>
    </div>
    <template v-else-if="server">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <header class="space-y-0.5">
          <h2 class="text-xl font-semibold tracking-tight">{{ server.name }}</h2>
          <p class="font-mono text-sm text-muted-foreground">{{ server.username }}@{{ server.host }}:{{ server.port }}</p>
        </header>
        <button :class="btnClass.default" :disabled="collecting" @click="collect">
          <Icon name="refresh" :class="collecting && 'animate-spin'" />Собрать сейчас
        </button>
      </div>

      <div v-if="collectMsg" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm" :class="collectMsg.ok ? 'text-foreground' : 'text-destructive'">{{ collectMsg.text }}</div>
      <div v-if="server.metrics_last_error" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">
        Последний сбор не удался: {{ server.metrics_last_error }}
      </div>
      <div v-if="latest?.alerts.length" class="grid gap-1 rounded-lg border bg-card px-4 py-3 text-sm">
        <div v-for="a in latest.alerts" :key="a.metric + a.target" class="flex items-center gap-2">
          <StatusBadge tone="danger" label="Превышение" />{{ alertLabel(a) }}
          <span class="ml-auto text-xs text-muted-foreground">с {{ formatDate(a.exceeded_since) }}</span>
        </div>
      </div>
      <p v-if="!server.metrics_settings.enabled" class="text-sm text-muted-foreground">Автоматический сбор выключен — включите его в настройках сервера. Можно собрать метрики вручную.</p>

      <section class="grid gap-3 rounded-xl border bg-card p-4">
        <div class="flex items-baseline justify-between gap-2">
          <h3 class="text-sm font-medium">Сейчас</h3>
          <span class="text-xs text-muted-foreground">{{ latest?.collected_at ? formatDate(latest.collected_at) : '' }}</span>
        </div>
        <ServerMetricsSummary v-if="latest?.metric" :metric="latest.metric" :settings="server.metrics_settings" />
        <p v-else class="py-4 text-center text-sm text-muted-foreground">Замеров пока не было. Нажмите «Собрать сейчас».</p>
      </section>

      <div class="flex items-center gap-2">
        <div class="inline-flex gap-1 rounded-lg bg-muted p-1">
          <button v-for="r in RANGES" :key="r.key" class="rounded-md px-3.5 py-1.5 text-sm" :class="range === r.key ? 'bg-card shadow-xs' : 'text-muted-foreground'" @click="range = r.key">{{ r.label }}</button>
        </div>
        <Icon v-if="histLoading" name="loader" class="animate-spin text-muted-foreground" />
      </div>
      <div v-if="histError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ histError }}</div>

      <template v-else>
        <section class="grid gap-2 rounded-xl border bg-card p-4">
          <h3 class="text-sm font-medium">Нагрузка на ядро <span class="font-normal text-muted-foreground">· LA 5 мин / ядер</span></h3>
          <TimeChart :series="loadSeries" :from="window.from" :to="window.to" :threshold="th?.load_per_core" :gap-ms="gapMs" :decimals="2" />
        </section>
        <section class="grid gap-2 rounded-xl border bg-card p-4">
          <h3 class="text-sm font-medium">Память</h3>
          <TimeChart :series="ramSeries" :from="window.from" :to="window.to" unit="%" :y-max="100" :threshold="th?.ram_percent" :gap-ms="gapMs" />
        </section>
        <section class="grid gap-2 rounded-xl border bg-card p-4">
          <h3 class="text-sm font-medium">Диски <span class="font-normal text-muted-foreground">· занято, %</span></h3>
          <TimeChart :series="diskSeries" :from="window.from" :to="window.to" unit="%" :y-max="100" :threshold="th?.disk_percent" :gap-ms="gapMs" />
        </section>
      </template>
    </template>
  </div>
</template>
