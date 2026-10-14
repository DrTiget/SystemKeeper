<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import {
  formatInterval,
  formatSpan,
  formatUptime,
  type Heartbeat,
  type Monitor,
  type MonitorCheckResult,
  type MonitorIncident,
  MONITOR_STATUS,
  MONITOR_TYPES,
  monitorTarget,
} from '@/api/monitors'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import SegmentedControl from '@/components/form/SegmentedControl.vue'
import HeartbeatBar from '@/components/monitors/HeartbeatBar.vue'
import ResponseChart from '@/components/monitors/ResponseChart.vue'
import { btnClass, formatDate } from '@/lib/ui'
import { useProjectsStore } from '@/stores/projects'

const route = useRoute()
const router = useRouter()
const id = Number(route.params.id)

const monitor = ref<Monitor | null>(null)
const loadError = ref('')
const actionError = ref('')
const projects = useProjectsStore()
watch(monitor, (m) => m && projects.setFor(m.project, { label: 'Аптайм', to: '/monitors' }, m.name))

// График и сбои
type Period = '24h' | '7d' | '30d'
const period = ref<Period>('24h')
const periodOptions: { value: Period; label: string }[] = [
  { value: '24h', label: '24 часа' },
  { value: '7d', label: '7 дней' },
  { value: '30d', label: '30 дней' },
]
const PERIOD_MS: Record<Period, number> = { '24h': 86400_000, '7d': 7 * 86400_000, '30d': 30 * 86400_000 }
const heartbeats = ref<Heartbeat[]>([])
const range = ref({ from: new Date(Date.now() - PERIOD_MS['24h']).toISOString(), to: new Date().toISOString() })
const incidents = ref<MonitorIncident[]>([])

async function loadHeartbeats() {
  try {
    heartbeats.value = (await api<{ data: Heartbeat[] }>(`/monitors/${id}/heartbeats?period=${period.value}`)).data
  } catch {
    heartbeats.value = []
  }
  range.value = { from: new Date(Date.now() - PERIOD_MS[period.value]).toISOString(), to: new Date().toISOString() }
}
watch(period, loadHeartbeats)

let timer: ReturnType<typeof setTimeout> | undefined
async function load() {
  clearTimeout(timer)
  try {
    const [m, inc] = await Promise.all([
      api<{ data: Monitor }>(`/monitors/${id}`),
      api<{ data: MonitorIncident[] }>(`/monitors/${id}/incidents?per_page=10`).catch(() => ({ data: [] as MonitorIncident[] })),
    ])
    monitor.value = m.data
    incidents.value = inc.data
  } catch (e) {
    if (!monitor.value) loadError.value = e instanceof ApiError && e.status === 404 ? 'Монитор не найден' : 'Не удалось загрузить монитор'
    return
  }
  await loadHeartbeats()
  timer = setTimeout(load, 30_000)
}
onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))

// Действия
const checking = ref(false)
const lastCheck = ref<MonitorCheckResult | null>(null)

async function checkNow() {
  checking.value = true
  actionError.value = ''
  try {
    const res = (await api<{ data: MonitorCheckResult }>(`/monitors/${id}/check`, { method: 'POST' })).data
    lastCheck.value = res
    if (res.monitor) monitor.value = res.monitor
    loadHeartbeats()
  } catch (e) {
    actionError.value = e instanceof ApiError ? e.message : 'Не удалось проверить'
  } finally {
    checking.value = false
  }
}

async function togglePause() {
  actionError.value = ''
  try {
    monitor.value = (await api<{ data: Monitor }>(`/monitors/${id}`, { method: 'PATCH', body: { enabled: !monitor.value!.enabled } })).data
  } catch (e) {
    actionError.value = e instanceof ApiError ? e.message : 'Не удалось изменить'
  }
}

const deleteOpen = ref(false)
const deleteError = ref('')
async function destroy() {
  deleteError.value = ''
  try {
    await api(`/monitors/${id}`, { method: 'DELETE' })
    router.push(monitor.value?.project ? { name: 'project', params: { id: monitor.value.project.id } } : { name: 'monitors' })
  } catch (e) {
    deleteError.value = e instanceof ApiError ? e.message : 'Не удалось удалить'
  }
}

// Что и как проверяется — для блока «Настройки»
const settings = computed(() => {
  const m = monitor.value
  if (!m) return []
  const rows: [string, string][] = [['Тип', MONITOR_TYPES.find((t) => t.value === m.type)!.label]]
  if (m.type === 'http') {
    rows.push(['Запрос', `${m.method} · ${m.follow_redirects ? 'с редиректами' : 'без редиректов'}`])
    rows.push(['Ожидаемый ответ', m.accepted_statuses.join(', ')])
    if (m.keyword) rows.push([m.keyword_invert ? 'На странице нет слова' : 'На странице есть слово', m.keyword])
    rows.push(['Сертификат', m.ignore_tls ? 'не проверяется' : m.ssl_expiry_days ? `предупредить за ${m.ssl_expiry_days} дн.` : 'проверяется'])
  }
  if (m.type === 'dns') {
    rows.push(['Запись', m.dns_record_type + (m.dns_expected ? ` = ${m.dns_expected}` : '')])
    rows.push(['DNS-сервер', m.dns_resolver || 'системный'])
  }
  rows.push(['Интервал', `каждые ${formatInterval(m.interval)}, тайм-аут ${m.timeout} с`])
  rows.push(['Сбой', m.retries ? `после ${m.retries + 1} неудач подряд (повтор через ${formatInterval(m.retry_interval)})` : 'после первой неудачи'])
  return rows
})

const uptimeClass = (v: number | null) => (v === null ? 'text-muted-foreground' : v < 95 ? 'text-destructive' : v < 99 ? 'text-amber-600 dark:text-amber-400' : '')
const card = 'rounded-xl border bg-card p-6 shadow-xs'
const tile = 'grid content-start gap-1 rounded-xl border bg-card p-4 shadow-xs'
</script>

<template>
  <div class="flex max-w-7xl flex-col gap-6 p-4">
    <RouterLink :to="monitor?.project ? `/projects/${monitor.project.id}` : '/monitors'" class="inline-flex items-center gap-1.5 self-start text-sm text-muted-foreground hover:text-foreground">
      <Icon name="arrow-left" class="size-4" />{{ monitor?.project ? monitor.project.name : 'Аптайм' }}
    </RouterLink>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>
    <p v-else-if="!monitor" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>

    <template v-else>
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="grid min-w-0 gap-1">
          <h2 class="flex items-center gap-2.5 text-xl font-semibold tracking-tight">
            <span class="size-3 shrink-0 rounded-full" :class="[MONITOR_STATUS[monitor.status].dot, monitor.status === 'down' && 'animate-pulse']" />
            <span class="truncate">{{ monitor.name }}</span>
          </h2>
          <a v-if="monitor.type === 'http' && monitor.url" :href="monitor.url" target="_blank" rel="noopener noreferrer" class="truncate font-mono text-sm text-muted-foreground hover:text-foreground hover:underline">{{ monitor.url }}</a>
          <span v-else class="font-mono text-sm text-muted-foreground">{{ monitorTarget(monitor) }}</span>
        </div>
        <div class="flex flex-wrap gap-2">
          <button :class="btnClass.outline" :disabled="checking" @click="checkNow"><Icon :name="checking ? 'loader' : 'refresh'" :class="checking && 'animate-spin'" />Проверить сейчас</button>
          <button :class="btnClass.outline" @click="togglePause"><Icon :name="monitor.enabled ? 'pause' : 'play'" />{{ monitor.enabled ? 'Пауза' : 'Возобновить' }}</button>
          <RouterLink :to="{ name: 'monitor-edit', params: { id: monitor.id } }" :class="btnClass.outline"><Icon name="pencil" />Изменить</RouterLink>
          <button :class="btnClass.destructive" class="px-3!" title="Удалить монитор" @click="(deleteOpen = true), (deleteError = '')"><Icon name="trash" /></button>
        </div>
      </header>

      <p v-if="actionError" class="text-sm text-destructive">{{ actionError }}</p>
      <div
        v-if="lastCheck"
        class="flex flex-wrap items-center gap-2 rounded-lg border px-4 py-2.5 text-sm"
        :class="lastCheck.status === 'down' ? 'border-destructive/40 bg-destructive/5' : 'bg-card'"
      >
        <span class="size-2 rounded-full" :class="MONITOR_STATUS[lastCheck.status].dot" />
        <span class="font-medium">Проверка: {{ MONITOR_STATUS[lastCheck.status].label.toLowerCase() }}</span>
        <span v-if="lastCheck.response_ms !== null" class="text-muted-foreground">· {{ lastCheck.response_ms }} мс</span>
        <span v-if="lastCheck.message" class="text-muted-foreground">· {{ lastCheck.message }}</span>
        <button class="ml-auto rounded p-0.5 text-muted-foreground hover:text-foreground" title="Скрыть" @click="lastCheck = null"><Icon name="x" class="size-4" /></button>
      </div>

      <!-- Сводка -->
      <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
        <div :class="tile" class="col-span-2 md:col-span-1 xl:col-span-2">
          <span class="text-sm text-muted-foreground">Сейчас</span>
          <span class="text-2xl font-semibold" :class="MONITOR_STATUS[monitor.status].text">{{ MONITOR_STATUS[monitor.status].label }}</span>
          <span v-if="monitor.status_since" class="text-xs text-muted-foreground">уже {{ formatSpan(monitor.status_since) }}, с {{ formatDate(monitor.status_since) }}</span>
          <span v-if="monitor.status === 'down' && monitor.last_error" class="text-xs break-words text-destructive">{{ monitor.last_error }}</span>
        </div>
        <div :class="tile">
          <span class="text-sm text-muted-foreground">Ответ</span>
          <span class="text-2xl font-semibold tabular-nums">{{ monitor.last_response_ms ?? '—' }}<span class="text-sm font-normal text-muted-foreground"> мс</span></span>
          <span class="text-xs text-muted-foreground">среднее за 24 ч — {{ monitor.avg_response_ms ?? '—' }} мс</span>
        </div>
        <div :class="tile">
          <span class="text-sm text-muted-foreground">Аптайм 24 ч</span>
          <span class="text-2xl font-semibold tabular-nums" :class="uptimeClass(monitor.uptime.h24)">{{ formatUptime(monitor.uptime.h24) }}</span>
        </div>
        <div :class="tile">
          <span class="text-sm text-muted-foreground">7 дней</span>
          <span class="text-2xl font-semibold tabular-nums" :class="uptimeClass(monitor.uptime.d7)">{{ formatUptime(monitor.uptime.d7) }}</span>
        </div>
        <div :class="tile">
          <span class="text-sm text-muted-foreground">30 дней</span>
          <span class="text-2xl font-semibold tabular-nums" :class="uptimeClass(monitor.uptime.d30)">{{ formatUptime(monitor.uptime.d30) }}</span>
        </div>
      </div>

      <section :class="card" class="grid gap-3">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <h3 class="font-semibold">Последние проверки</h3>
          <span class="text-xs text-muted-foreground">
            каждые {{ formatInterval(monitor.interval) }}<template v-if="monitor.last_check_at"> · последняя {{ formatDate(monitor.last_check_at) }}</template>
          </span>
        </div>
        <HeartbeatBar :beats="monitor.recent" :slots="60" size="md" />
        <div class="flex justify-between text-xs text-muted-foreground"><span>раньше</span><span>сейчас</span></div>
      </section>

      <section :class="card" class="grid gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h3 class="font-semibold">Время ответа</h3>
          <SegmentedControl v-model="period" :options="periodOptions" />
        </div>
        <ResponseChart :beats="heartbeats" :from="range.from" :to="range.to" />
        <p class="flex items-center gap-1.5 text-xs text-muted-foreground"><span class="inline-block h-2 w-[3px] rounded-full bg-destructive" />— проверка не прошла</p>
      </section>

      <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <section :class="card" class="grid gap-4">
          <h3 class="font-semibold">Сбои</h3>
          <ul v-if="incidents.length" class="divide-y rounded-lg border text-sm">
            <li v-for="i in incidents" :key="i.id" class="flex flex-wrap items-start gap-x-4 gap-y-1 px-3 py-2.5">
              <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="i.ended_at ? 'bg-muted-foreground' : 'animate-pulse bg-destructive'" />
              <span class="grid min-w-0 flex-1 gap-0.5">
                <span>{{ formatDate(i.started_at) }}<template v-if="i.ended_at"> — {{ formatDate(i.ended_at) }}</template><span v-else class="text-destructive"> — продолжается</span></span>
                <span v-if="i.error" class="text-xs break-words text-muted-foreground">{{ i.error }}</span>
              </span>
              <span class="text-sm whitespace-nowrap tabular-nums" :class="!i.ended_at && 'text-destructive'">{{ formatSpan(i.started_at, i.ended_at) }}</span>
            </li>
          </ul>
          <p v-else class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="check" class="size-4 text-primary" />Сбоев не было</p>
        </section>

        <aside class="grid gap-6">
          <section v-if="monitor.ssl" :class="card" class="grid gap-2 p-4!">
            <h3 class="flex items-center gap-2 font-semibold"><Icon name="shield" class="size-4 text-muted-foreground" />Сертификат</h3>
            <p class="text-sm">
              <span :class="monitor.ssl.days_left <= (monitor.ssl_expiry_days ?? 0) ? 'font-medium text-destructive' : ''">
                {{ monitor.ssl.days_left > 0 ? `истекает через ${monitor.ssl.days_left} дн.` : 'истёк' }}
              </span>
              <span class="text-muted-foreground"> · до {{ new Date(monitor.ssl.valid_to).toLocaleDateString('ru-RU') }}</span>
            </p>
            <p v-if="monitor.ssl.issuer" class="text-xs text-muted-foreground">Выдан: {{ monitor.ssl.issuer }}</p>
          </section>

          <section :class="card" class="grid gap-3 p-4!">
            <div class="flex items-center justify-between gap-2">
              <h3 class="font-semibold">Как проверяем</h3>
              <RouterLink :to="{ name: 'monitor-edit', params: { id: monitor.id } }" class="text-sm font-medium text-primary hover:underline">Изменить</RouterLink>
            </div>
            <dl class="grid gap-2.5 text-sm">
              <div v-for="[k, v] in settings" :key="k" class="grid gap-0.5">
                <dt class="text-xs text-muted-foreground">{{ k }}</dt>
                <dd class="break-words">{{ v }}</dd>
              </div>
            </dl>
          </section>
        </aside>
      </div>
    </template>

    <Modal v-if="deleteOpen && monitor" title="Удалить монитор?" @close="deleteOpen = false">
      <p class="text-sm text-muted-foreground">«{{ monitor.name }}» и вся история его проверок будут удалены.</p>
      <p v-if="deleteError" class="mt-3 text-sm text-destructive">{{ deleteError }}</p>
      <div class="mt-6 flex justify-end gap-2">
        <button :class="btnClass.outline" @click="deleteOpen = false">Отмена</button>
        <button :class="btnClass.destructive" @click="destroy">Удалить</button>
      </div>
    </Modal>
  </div>
</template>
