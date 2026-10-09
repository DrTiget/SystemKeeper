<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { api } from '@/api/client'
import type { Incident, Monitor, Paginated } from '@/api/types'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import Icon from '@/components/Icon.vue'
import IncidentTable from '@/components/IncidentTable.vue'
import MonitorFormModal from '@/components/MonitorFormModal.vue'
import MonitorStatus from '@/components/MonitorStatus.vue'
import Pagination from '@/components/Pagination.vue'
import { btnClass, errorMessage } from '@/lib/format'
import { TYPES, formatPercent } from '@/lib/monitoring'

const tab = ref<'monitors' | 'incidents'>('monitors')

const monitors = ref<Monitor[]>([])
const mMeta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 })

const incidents = ref<Incident[]>([])
const iMeta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
const iFilter = ref<'' | 'open' | 'resolved'>('')

const loading = ref(true)
const loadError = ref('')

async function loadMonitors(page = mMeta.value.current_page) {
  const res = await api<Paginated<Monitor>>(`/monitors?page=${page}&per_page=15`)
  if (!res.data.length && page > res.meta.last_page && res.meta.last_page > 0) return loadMonitors(res.meta.last_page)
  monitors.value = res.data
  mMeta.value = res.meta
}

async function loadIncidents(page = iMeta.value.current_page) {
  const q = iFilter.value ? `&status=${iFilter.value}` : ''
  const res = await api<Paginated<Incident>>(`/incidents?page=${page}&per_page=15${q}`)
  if (!res.data.length && page > res.meta.last_page && res.meta.last_page > 0) return loadIncidents(res.meta.last_page)
  incidents.value = res.data
  iMeta.value = res.meta
}

async function load(silent = false) {
  if (!silent) loading.value = true
  loadError.value = ''
  try {
    await (tab.value === 'monitors' ? loadMonitors() : loadIncidents())
  } catch (e) {
    if (!silent) loadError.value = errorMessage(e, 'Не удалось загрузить данные')
  } finally {
    loading.value = false
  }
}
function setTab(t: 'monitors' | 'incidents') {
  tab.value = t
  load()
}
function setFilter(f: '' | 'open' | 'resolved') {
  iFilter.value = f
  loadIncidents(1).catch((e) => (loadError.value = errorMessage(e)))
}

let timer: number
onMounted(() => {
  load()
  timer = window.setInterval(() => load(true), 30_000)
})
onUnmounted(() => clearInterval(timer))

// --- форма ---
const editing = ref<'new' | Monitor | null>(null)
async function saved() {
  editing.value = null
  await load()
}

// --- пауза/возобновление ---
const actionError = ref('')
async function togglePause(m: Monitor) {
  actionError.value = ''
  try {
    const res = await api<{ data: Monitor }>(`/monitors/${m.id}/${m.enabled ? 'pause' : 'resume'}`, { method: 'POST' })
    const i = monitors.value.findIndex((x) => x.id === m.id)
    if (i >= 0) monitors.value[i] = res.data
  } catch (e) {
    actionError.value = errorMessage(e, 'Не удалось изменить состояние монитора')
  }
}

// --- удаление ---
const removing = ref<Monitor | null>(null)
const removeLoading = ref(false)
const removeError = ref('')
async function remove() {
  if (!removing.value) return
  removeLoading.value = true
  removeError.value = ''
  try {
    await api(`/monitors/${removing.value.id}`, { method: 'DELETE' })
    removing.value = null
    await load()
  } catch (e) {
    removeError.value = errorMessage(e)
  } finally {
    removeLoading.value = false
  }
}

const tabCls = (t: string) => (tab.value === t ? btnClass.default : btnClass.outline)
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <div class="flex items-start justify-between gap-4">
      <header class="space-y-0.5">
        <h2 class="text-xl font-semibold tracking-tight">Мониторинг</h2>
        <p class="text-sm text-muted-foreground">Доступность сайтов, портов и заданий, инциденты. Данные обновляются раз в 30 секунд.</p>
      </header>
      <button :class="btnClass.default" @click="editing = 'new'"><Icon name="plus" />Добавить монитор</button>
    </div>

    <div class="flex flex-wrap items-center gap-2">
      <button :class="tabCls('monitors')" @click="setTab('monitors')">Мониторы</button>
      <button :class="tabCls('incidents')" @click="setTab('incidents')">Инциденты</button>
      <template v-if="tab === 'incidents'">
        <span class="mx-2 h-5 w-px bg-border" />
        <button :class="iFilter === '' ? btnClass.default : btnClass.outlineSm" @click="setFilter('')">Все</button>
        <button :class="iFilter === 'open' ? btnClass.default : btnClass.outlineSm" @click="setFilter('open')">Активные</button>
        <button :class="iFilter === 'resolved' ? btnClass.default : btnClass.outlineSm" @click="setFilter('resolved')">Закрытые</button>
      </template>
    </div>

    <div v-if="actionError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ actionError }}</div>
    <div v-if="loadError" role="alert" class="flex items-center gap-3 rounded-lg border bg-card px-4 py-3 text-sm text-destructive">
      {{ loadError }}
      <button :class="[btnClass.outlineSm, 'ml-auto']" @click="load()">Повторить</button>
    </div>
    <div v-else-if="loading" class="rounded-xl border p-10 text-center text-sm text-muted-foreground">Загрузка…</div>

    <template v-else-if="tab === 'monitors'">
      <div v-if="!monitors.length" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
        Мониторов пока нет. Добавьте первый — сайт, порт, сертификат или heartbeat.
      </div>
      <template v-else>
        <div class="overflow-x-auto rounded-xl border">
          <table class="w-full text-sm">
            <thead class="border-b text-left text-xs text-muted-foreground">
              <tr>
                <th class="px-4 py-2 font-medium">Монитор</th>
                <th class="px-4 py-2 font-medium">Статус</th>
                <th class="px-4 py-2 font-medium">Uptime 24ч</th>
                <th class="px-4 py-2 font-medium">7д</th>
                <th class="px-4 py-2 font-medium">30д</th>
                <th class="px-4 py-2 font-medium">Ответ</th>
                <th class="px-4 py-2" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in monitors" :key="m.id" class="border-b last:border-0" :class="!m.enabled && 'opacity-70'">
                <td class="max-w-xs px-4 py-2">
                  <RouterLink :to="`/monitoring/${m.id}`" class="block truncate font-mono font-medium hover:underline">{{ m.name }}</RouterLink>
                  <span class="block truncate text-xs text-muted-foreground">{{ TYPES[m.type] }}<template v-if="m.target"> · {{ m.target }}</template></span>
                </td>
                <td class="px-4 py-2">
                  <MonitorStatus :monitor="m" />
                  <span v-if="m.enabled && m.status === 'down' && m.last_error" class="mt-0.5 block max-w-48 truncate text-xs text-muted-foreground" :title="m.last_error">{{ m.last_error }}</span>
                </td>
                <td class="px-4 py-2 tabular-nums">{{ formatPercent(m.uptime?.['24h'].percent) }}</td>
                <td class="px-4 py-2 tabular-nums">{{ formatPercent(m.uptime?.['7d'].percent) }}</td>
                <td class="px-4 py-2 tabular-nums">{{ formatPercent(m.uptime?.['30d'].percent) }}</td>
                <td class="px-4 py-2 tabular-nums">{{ m.last_response_time_ms != null ? `${m.last_response_time_ms} мс` : '—' }}</td>
                <td class="px-4 py-2 text-right whitespace-nowrap">
                  <button :class="btnClass.ghostSm" :title="m.enabled ? 'Поставить на паузу' : 'Возобновить'" @click="togglePause(m)">{{ m.enabled ? 'Пауза' : 'Возобновить' }}</button>
                  <button :class="btnClass.ghostSm" title="Редактировать" @click="editing = m"><Icon name="pencil" /></button>
                  <button :class="btnClass.ghostSm" title="Удалить" @click="removeError = ''; removing = m"><Icon name="trash" /></button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <Pagination :page="mMeta.current_page" :last-page="mMeta.last_page" :total="mMeta.total" @change="(p) => loadMonitors(p).catch((e) => (loadError = errorMessage(e)))" />
      </template>
    </template>

    <template v-else>
      <div v-if="!incidents.length" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">Инцидентов нет.</div>
      <template v-else>
        <IncidentTable :items="incidents" show-monitor />
        <Pagination :page="iMeta.current_page" :last-page="iMeta.last_page" :total="iMeta.total" @change="(p) => loadIncidents(p).catch((e) => (loadError = errorMessage(e)))" />
      </template>
    </template>

    <MonitorFormModal v-if="editing" :monitor="editing === 'new' ? null : editing" @saved="saved" @close="editing = null" />

    <ConfirmDialog
      v-if="removing"
      title="Удалить монитор?"
      :text="`Монитор «${removing.name}», его проверки и инциденты будут удалены безвозвратно.`"
      :loading="removeLoading"
      :error="removeError"
      @confirm="remove"
      @close="removing = null"
    />
  </div>
</template>
