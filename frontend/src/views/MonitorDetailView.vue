<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import type { Incident, Monitor, MonitorCheck, Paginated, Uptime } from '@/api/types'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import CopyButton from '@/components/CopyButton.vue'
import Icon from '@/components/Icon.vue'
import IncidentTable from '@/components/IncidentTable.vue'
import MonitorFormModal from '@/components/MonitorFormModal.vue'
import MonitorStatus from '@/components/MonitorStatus.vue'
import Pagination from '@/components/Pagination.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { btnClass, errorMessage, formatDate } from '@/lib/format'
import { TYPES, formatPercent } from '@/lib/monitoring'

const route = useRoute()
const router = useRouter()
const id = computed(() => route.params.id as string)

const monitor = ref<Monitor | null>(null)
const uptime = ref<Uptime | null>(null)
const chart = ref<MonitorCheck[]>([])
const checks = ref<MonitorCheck[]>([])
const cMeta = ref({ current_page: 1, last_page: 1, per_page: 20, total: 0 })
const incidents = ref<Incident[]>([])
const loading = ref(true)
const loadError = ref('')
const notFound = ref(false)

async function loadChecks(page = cMeta.value.current_page) {
  const res = await api<Paginated<MonitorCheck>>(`/monitors/${id.value}/checks?page=${page}&per_page=20`)
  checks.value = res.data
  cMeta.value = res.meta
}

async function load(silent = false) {
  if (!silent) loading.value = true
  loadError.value = ''
  try {
    const [m, c, i] = await Promise.all([
      api<{ data: Monitor }>(`/monitors/${id.value}`),
      api<Paginated<MonitorCheck>>(`/monitors/${id.value}/checks?per_page=60`),
      api<Paginated<Incident>>(`/monitors/${id.value}/incidents?per_page=10`),
    ])
    monitor.value = m.data
    uptime.value = m.data.uptime ?? null
    chart.value = [...c.data].reverse()
    incidents.value = [...i.data].sort((a, b) => Number(b.status === 'open') - Number(a.status === 'open'))
    await loadChecks()
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) notFound.value = true
    else if (!silent) loadError.value = errorMessage(e, 'Не удалось загрузить монитор')
  } finally {
    loading.value = false
  }
}

let timer: number
onMounted(() => {
  load()
  timer = window.setInterval(() => load(true), 30_000)
})
onUnmounted(() => clearInterval(timer))

// --- график: viewBox 600×160, ось X — порядок проверок, Y — мс ---
const W = 600
const H = 160
const PAD = { l: 44, r: 8, t: 8, b: 20 }
const maxMs = computed(() => {
  const m = Math.max(0, ...chart.value.map((c) => c.response_time_ms ?? 0))
  return m <= 0 ? 100 : Math.ceil(m * 1.1)
})
const px = (i: number) => PAD.l + (chart.value.length <= 1 ? (W - PAD.l - PAD.r) / 2 : (i * (W - PAD.l - PAD.r)) / (chart.value.length - 1))
const py = (ms: number) => PAD.t + (H - PAD.t - PAD.b) * (1 - ms / maxMs.value)
// линия рвётся на проверках без времени ответа
const segments = computed(() => {
  const out: string[] = []
  let cur: string[] = []
  chart.value.forEach((c, i) => {
    if (c.response_time_ms != null) cur.push(`${px(i).toFixed(1)},${py(c.response_time_ms).toFixed(1)}`)
    else if (cur.length) (out.push(cur.join(' ')), (cur = []))
  })
  if (cur.length) out.push(cur.join(' '))
  return out
})
const failedMarks = computed(() => chart.value.map((c, i) => ({ c, x: px(i) })).filter((p) => p.c.status === 'down'))
const yTicks = computed(() => [0, 0.5, 1].map((f) => ({ ms: Math.round(maxMs.value * f), y: py(maxMs.value * f) })))

// --- действия ---
const actionError = ref('')
async function togglePause() {
  if (!monitor.value) return
  actionError.value = ''
  try {
    monitor.value = (await api<{ data: Monitor }>(`/monitors/${id.value}/${monitor.value.enabled ? 'pause' : 'resume'}`, { method: 'POST' })).data
  } catch (e) {
    actionError.value = errorMessage(e, 'Не удалось изменить состояние монитора')
  }
}
const editing = ref(false)
async function saved() {
  editing.value = false
  await load()
}
const removing = ref(false)
const removeLoading = ref(false)
const removeError = ref('')
async function remove() {
  removeLoading.value = true
  removeError.value = ''
  try {
    await api(`/monitors/${id.value}`, { method: 'DELETE' })
    router.push('/monitoring')
  } catch (e) {
    removeError.value = errorMessage(e)
  } finally {
    removeLoading.value = false
  }
}
const windows = [
  ['24h', '24 часа'],
  ['7d', '7 дней'],
  ['30d', '30 дней'],
] as const
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <RouterLink to="/monitoring" class="text-sm text-muted-foreground hover:underline">← Все мониторы</RouterLink>

    <div v-if="notFound" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">Монитор не найден — возможно, он удалён.</div>
    <div v-else-if="loadError" role="alert" class="flex items-center gap-3 rounded-lg border bg-card px-4 py-3 text-sm text-destructive">
      {{ loadError }}
      <button :class="[btnClass.outlineSm, 'ml-auto']" @click="load()">Повторить</button>
    </div>
    <div v-else-if="loading || !monitor" class="rounded-xl border p-10 text-center text-sm text-muted-foreground">Загрузка…</div>

    <template v-else>
      <div class="flex flex-wrap items-start justify-between gap-4">
        <header class="min-w-0 space-y-1">
          <div class="flex items-center gap-3">
            <h2 class="truncate font-mono text-xl font-semibold tracking-tight">{{ monitor.name }}</h2>
            <MonitorStatus :monitor="monitor" />
          </div>
          <p class="text-sm text-muted-foreground">
            {{ TYPES[monitor.type] }}<template v-if="monitor.target"> · <span class="font-mono">{{ monitor.target }}</span></template>
            · каждые {{ monitor.interval_seconds }} с
          </p>
        </header>
        <div class="flex gap-2">
          <button :class="btnClass.outline" @click="togglePause">{{ monitor.enabled ? 'Пауза' : 'Возобновить' }}</button>
          <button :class="btnClass.outline" @click="editing = true"><Icon name="pencil" />Изменить</button>
          <button :class="btnClass.outline" title="Удалить" @click="removeError = ''; removing = true"><Icon name="trash" /></button>
        </div>
      </div>

      <div v-if="actionError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ actionError }}</div>
      <div v-if="monitor.open_incident" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">
        Активный инцидент с {{ formatDate(monitor.open_incident.started_at) }}<template v-if="monitor.open_incident.reason">: {{ monitor.open_incident.reason }}</template>
      </div>

      <div v-if="monitor.type === 'heartbeat'" class="grid gap-2 rounded-xl border bg-card p-4">
        <span class="text-sm font-medium">URL для пинга</span>
        <div class="flex flex-wrap items-center gap-2">
          <code class="min-w-0 flex-1 rounded bg-muted px-2 py-1.5 font-mono text-[13px] break-all">{{ monitor.heartbeat_url }}</code>
          <CopyButton v-if="monitor.heartbeat_url" :text="monitor.heartbeat_url" />
        </div>
        <p class="text-xs text-muted-foreground">
          GET или POST без авторизации, например из cron: <code class="font-mono">curl -fsS {{ monitor.heartbeat_url }}</code>.
          Последний пинг: {{ formatDate(monitor.last_heartbeat_at) }}. Допуск — {{ monitor.heartbeat_grace_seconds }} с.
        </p>
      </div>

      <div class="grid gap-4 sm:grid-cols-3">
        <div v-for="[k, label] in windows" :key="k" class="rounded-xl border bg-card p-4">
          <div class="text-xs text-muted-foreground">Uptime · {{ label }}</div>
          <div class="text-2xl font-semibold tabular-nums">{{ formatPercent(uptime?.[k].percent) }}</div>
          <div class="text-xs text-muted-foreground tabular-nums">проверок: {{ uptime?.[k].checks ?? 0 }}, неудачных: {{ uptime?.[k].failed ?? 0 }}</div>
        </div>
      </div>

      <section class="grid gap-2 rounded-xl border bg-card p-4">
        <h3 class="text-sm font-medium">Время ответа <span class="font-normal text-muted-foreground">· последние {{ chart.length }} проверок</span></h3>
        <p v-if="!chart.length" class="py-8 text-center text-sm text-muted-foreground">Проверок пока не было.</p>
        <svg v-else :viewBox="`0 0 ${W} ${H}`" class="w-full" role="img" aria-label="График времени ответа">
          <g v-for="t in yTicks" :key="t.ms">
            <line :x1="PAD.l" :x2="W - PAD.r" :y1="t.y" :y2="t.y" class="stroke-border" stroke-width="1" />
            <text :x="PAD.l - 6" :y="t.y + 3" text-anchor="end" class="fill-muted-foreground" font-size="10">{{ t.ms }} мс</text>
          </g>
          <line v-for="f in failedMarks" :key="f.c.id" :x1="f.x" :x2="f.x" :y1="PAD.t" :y2="H - PAD.b" class="stroke-destructive" stroke-opacity="0.35" stroke-width="3" />
          <polyline v-for="(s, i) in segments" :key="i" :points="s" fill="none" class="stroke-chart-1" stroke-width="2" stroke-linejoin="round" />
          <circle v-for="(c, i) in chart" :key="c.id" :cx="px(i)" :cy="c.response_time_ms != null ? py(c.response_time_ms) : H - PAD.b" r="2.5" :class="c.status === 'down' ? 'fill-destructive' : 'fill-chart-1'">
            <title>{{ formatDate(c.checked_at) }} — {{ c.status === 'up' ? 'успешно' : 'сбой' }}{{ c.response_time_ms != null ? `, ${c.response_time_ms} мс` : '' }}</title>
          </circle>
        </svg>
        <p v-if="failedMarks.length" class="text-xs text-muted-foreground">Красные отметки — неудачные проверки.</p>
      </section>

      <section class="grid gap-2">
        <h3 class="text-sm font-medium">Инциденты</h3>
        <div v-if="!incidents.length" class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">Инцидентов не было.</div>
        <IncidentTable v-else :items="incidents" />
      </section>

      <section class="grid gap-2">
        <h3 class="text-sm font-medium">История проверок</h3>
        <div v-if="!checks.length" class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">Проверок пока не было.</div>
        <template v-else>
          <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
              <thead class="border-b text-left text-xs text-muted-foreground">
                <tr>
                  <th class="px-4 py-2 font-medium">Время</th>
                  <th class="px-4 py-2 font-medium">Результат</th>
                  <th class="px-4 py-2 font-medium">Ответ</th>
                  <th class="px-4 py-2 font-medium">Код</th>
                  <th class="px-4 py-2 font-medium">Ошибка</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="c in checks" :key="c.id" class="border-b last:border-0">
                  <td class="px-4 py-2 whitespace-nowrap tabular-nums">{{ formatDate(c.checked_at) }}</td>
                  <td class="px-4 py-2"><StatusBadge :tone="c.status === 'up' ? 'success' : 'danger'" :label="c.status === 'up' ? 'Успешно' : 'Сбой'" /></td>
                  <td class="px-4 py-2 tabular-nums">{{ c.response_time_ms != null ? `${c.response_time_ms} мс` : '—' }}</td>
                  <td class="px-4 py-2 tabular-nums">{{ c.status_code ?? '—' }}</td>
                  <td class="max-w-md px-4 py-2 break-words text-muted-foreground">{{ c.error || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <Pagination :page="cMeta.current_page" :last-page="cMeta.last_page" :total="cMeta.total" @change="(p) => loadChecks(p).catch((e) => (actionError = errorMessage(e)))" />
        </template>
      </section>

      <MonitorFormModal v-if="editing" :monitor="monitor" @saved="saved" @close="editing = false" />
      <ConfirmDialog
        v-if="removing"
        title="Удалить монитор?"
        :text="`Монитор «${monitor.name}», его проверки и инциденты будут удалены безвозвратно.`"
        :loading="removeLoading"
        :error="removeError"
        @confirm="remove"
        @close="removing = false"
      />
    </template>
  </div>
</template>
