<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { api } from '@/api/client'
import type { Dashboard } from '@/api/types'
import StatusBadge from '@/components/StatusBadge.vue'
import { btnClass, errorMessage, formatBytes, formatDate } from '@/lib/format'
import { formatPercent } from '@/lib/monitoring'

const data = ref<Dashboard | null>(null)
const loading = ref(true)
const loadError = ref('')

async function load(silent = false) {
  if (!silent) loading.value = true
  loadError.value = ''
  try {
    data.value = (await api<{ data: Dashboard }>('/dashboard')).data
  } catch (e) {
    if (!silent) loadError.value = errorMessage(e, 'Не удалось загрузить сводку')
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

const tile = 'rounded-xl border p-4 hover:bg-muted/50'
const panel = 'rounded-xl border p-4'
const row = '-mx-2 flex items-center gap-3 rounded-md px-2 py-1.5 text-sm hover:bg-muted'

/** Заполнение хранилища: тон полосы по порогам из дизайн-системы. */
function usage(t: Dashboard['storages'][number]) {
  if (t.total_space == null || t.free_space == null || t.total_space <= 0) return null
  const pct = Math.min(100, Math.max(0, Math.round(((t.total_space - t.free_space) / t.total_space) * 100)))
  return { pct, cls: pct >= 90 ? 'bg-destructive' : pct >= 70 ? 'bg-warning' : 'bg-chart-1' }
}
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="space-y-0.5">
      <h2 class="text-xl font-semibold tracking-tight">Дашборд</h2>
      <p class="text-sm text-muted-foreground">Сводка по серверам, бекапам, мониторингу и хранилищам. Обновляется раз в 30 секунд.</p>
    </header>

    <div v-if="loadError" role="alert" class="flex items-center gap-3 rounded-lg border bg-card px-4 py-3 text-sm text-destructive">
      {{ loadError }}
      <button :class="[btnClass.outlineSm, 'ml-auto']" @click="load()">Повторить</button>
    </div>
    <div v-else-if="loading || !data" class="rounded-xl border p-10 text-center text-sm text-muted-foreground">Загрузка…</div>

    <template v-else>
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <RouterLink to="/servers" :class="tile">
          <div class="text-sm text-muted-foreground">Серверы</div>
          <div class="mt-1 text-2xl font-semibold tabular-nums">{{ data.servers.total }}</div>
          <div class="mt-1 text-xs text-muted-foreground">
            доступны {{ data.servers.check_ok }} · недоступны {{ data.servers.check_failed }}<template v-if="data.servers.unchecked"> · не проверены {{ data.servers.unchecked }}</template>
          </div>
        </RouterLink>
        <RouterLink to="/backups" :class="tile">
          <div class="text-sm text-muted-foreground">Бекапы за 24 ч</div>
          <div class="mt-1 text-2xl font-semibold tabular-nums">{{ data.backups.success_24h }} <span class="text-base font-normal text-muted-foreground">/ {{ data.backups.failed_24h }} ош.</span></div>
          <div class="mt-1 text-xs text-muted-foreground">за 7 дней: {{ data.backups.success_7d }} успешных, {{ data.backups.failed_7d }} с ошибкой</div>
        </RouterLink>
        <RouterLink to="/monitoring" :class="tile">
          <div class="text-sm text-muted-foreground">Мониторы</div>
          <div class="mt-1 text-2xl font-semibold tabular-nums">{{ data.monitoring.up }} <span class="text-base font-normal text-muted-foreground">/ {{ data.monitoring.total }} работают</span></div>
          <div class="mt-1 text-xs text-muted-foreground">недоступны {{ data.monitoring.down }} · на паузе {{ data.monitoring.paused }}<template v-if="data.monitoring.pending"> · ожидают {{ data.monitoring.pending }}</template></div>
        </RouterLink>
        <RouterLink to="/monitoring" :class="tile">
          <div class="text-sm text-muted-foreground">Uptime</div>
          <div class="mt-1 text-2xl font-semibold tabular-nums">{{ formatPercent(data.monitoring.uptime_24h) }}</div>
          <div class="mt-1 text-xs text-muted-foreground">за 24 ч · за 30 дней {{ formatPercent(data.monitoring.uptime_30d) }}</div>
        </RouterLink>
      </div>

      <div class="grid gap-3 lg:grid-cols-2">
        <section :class="panel">
          <div class="mb-3 flex items-center justify-between">
            <h3 class="font-medium">Активные инциденты <span class="text-muted-foreground tabular-nums">{{ data.monitoring.active_incidents_count }}</span></h3>
            <RouterLink to="/monitoring" class="text-xs text-link hover:underline">Все</RouterLink>
          </div>
          <p v-if="!data.monitoring.active_incidents.length" class="text-sm text-muted-foreground">Активных инцидентов нет.</p>
          <RouterLink v-for="i in data.monitoring.active_incidents" :key="i.id" :to="`/monitoring/${i.monitor_id}`" :class="row">
            <span class="min-w-0 flex-1">
              <span class="block truncate font-mono font-medium">{{ i.monitor_name ?? `#${i.monitor_id}` }}</span>
              <span v-if="i.reason" class="block truncate text-xs text-muted-foreground" :title="i.reason">{{ i.reason }}</span>
            </span>
            <span class="w-24 text-right text-xs text-muted-foreground">{{ formatDate(i.started_at) }}</span>
            <StatusBadge tone="danger" label="Открыт" />
          </RouterLink>
        </section>

        <section :class="panel">
          <div class="mb-3 flex items-center justify-between">
            <h3 class="font-medium">Последние ошибки бекапов</h3>
            <RouterLink to="/backups" class="text-xs text-link hover:underline">Все</RouterLink>
          </div>
          <p v-if="!data.backups.last_failed.length" class="text-sm text-muted-foreground">Неудачных бекапов нет.</p>
          <RouterLink v-for="b in data.backups.last_failed" :key="b.id" to="/backups" :class="row">
            <span class="min-w-0 flex-1">
              <span class="block truncate font-mono font-medium">{{ b.job_name ?? `задание #${b.backup_job_id}` }}</span>
              <span v-if="b.error" class="block truncate text-xs text-muted-foreground" :title="b.error">{{ b.error }}</span>
            </span>
            <span class="w-24 text-right text-xs text-muted-foreground">{{ formatDate(b.finished_at) }}</span>
            <StatusBadge tone="danger" label="Ошибка" />
          </RouterLink>
        </section>

        <section :class="panel">
          <div class="mb-3 flex items-center justify-between">
            <h3 class="font-medium">Ближайшие запуски</h3>
            <RouterLink to="/backups" class="text-xs text-link hover:underline">Задания</RouterLink>
          </div>
          <p v-if="!data.backups.upcoming.length" class="text-sm text-muted-foreground">Заданий с расписанием нет.</p>
          <RouterLink v-for="j in data.backups.upcoming" :key="j.backup_job_id" to="/backups" :class="row">
            <span class="min-w-0 flex-1 truncate font-mono font-medium">{{ j.job_name }}</span>
            <span class="text-right text-xs text-muted-foreground">{{ formatDate(j.next_run_at) }}</span>
          </RouterLink>
        </section>

        <section :class="panel">
          <div class="mb-3 flex items-center justify-between">
            <h3 class="font-medium">Без свежего бекапа</h3>
            <span class="text-xs text-muted-foreground">дольше {{ data.backups.stale_days }} дн.</span>
          </div>
          <p v-if="!data.backups.stale_jobs.length" class="text-sm text-muted-foreground">Все активные задания с успешным бекапом.</p>
          <RouterLink v-for="j in data.backups.stale_jobs" :key="j.backup_job_id" to="/backups" :class="row">
            <span class="min-w-0 flex-1 truncate font-mono font-medium">{{ j.job_name }}</span>
            <span class="text-right text-xs text-muted-foreground">{{ j.last_success_at ? formatDate(j.last_success_at) : 'ни разу' }}</span>
            <StatusBadge tone="warning" label="Давно" />
          </RouterLink>
        </section>

        <section :class="[panel, 'lg:col-span-2']">
          <div class="mb-3 flex items-center justify-between">
            <h3 class="font-medium">Хранилища</h3>
            <RouterLink to="/storage-targets" class="text-xs text-link hover:underline">Все</RouterLink>
          </div>
          <div class="mb-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
            <span><span class="text-muted-foreground">Локально:</span> <span class="font-mono tabular-nums">{{ formatBytes(data.backups.local_size_bytes) }}</span></span>
            <span v-for="s in data.backups.size_by_storage" :key="s.storage_target_id">
              <span class="text-muted-foreground">{{ s.name }}:</span> <span class="font-mono tabular-nums">{{ formatBytes(s.size_bytes) }}</span>
              <span class="text-xs text-muted-foreground"> ({{ s.copies }} шт.)</span>
            </span>
          </div>
          <p v-if="!data.storages.length" class="text-sm text-muted-foreground">Хранилищ пока нет.</p>
          <div class="grid gap-x-6 gap-y-3 md:grid-cols-2">
            <RouterLink v-for="t in data.storages" :key="t.id" to="/storage-targets" :class="[row, 'flex-col !items-stretch !gap-1']">
              <div class="flex items-center gap-2">
                <span class="min-w-0 flex-1 truncate font-mono font-medium">{{ t.name }}</span>
                <StatusBadge v-if="!t.enabled" tone="neutral" label="Отключено" />
                <StatusBadge v-else-if="t.last_check_status === 'failed'" tone="danger" label="Нет доступа" />
                <StatusBadge v-else-if="t.last_check_status === 'ok'" tone="success" label="Норма" />
                <StatusBadge v-else tone="neutral" label="Не проверялось" />
              </div>
              <template v-if="usage(t)">
                <div class="flex justify-between text-xs text-muted-foreground">
                  <span>свободно {{ formatBytes(t.free_space) }} из {{ formatBytes(t.total_space) }}</span>
                  <span class="tabular-nums">{{ usage(t)!.pct }}%</span>
                </div>
                <div class="h-1.5 rounded-full bg-muted"><div class="h-1.5 rounded-full" :class="usage(t)!.cls" :style="{ width: `${usage(t)!.pct}%` }" /></div>
              </template>
              <div v-else class="text-xs text-muted-foreground">Место не сообщается или хранилище не проверялось.</div>
            </RouterLink>
          </div>
        </section>
      </div>
    </template>
  </div>
</template>
