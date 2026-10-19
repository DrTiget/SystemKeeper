<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { ApiError } from '@/api/client'
import { auditSettings, listAuditLogs, saveAuditSettings, type AuditLogEntry, type AuditPage } from '@/api/audit'
import Icon from '@/components/Icon.vue'
import { btnClass, formatDate, inputClass } from '@/lib/ui'

const logs = ref<AuditLogEntry[]>([])
const meta = ref<AuditPage['meta'] | null>(null)
const page = ref(1)
const loading = ref(false)
const loadError = ref('')
const filters = reactive({ actor_type: '', event: '', subject_type: '', from: '', to: '', search: '' })
const expanded = ref<number | null>(null)

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const params: Record<string, string> = { page: String(page.value) }
    for (const [k, v] of Object.entries(filters)) if (v.trim()) params[k] = v.trim()
    const res = await listAuditLogs(params)
    logs.value = res.data
    meta.value = res.meta
  } catch {
    loadError.value = 'Не удалось загрузить журнал'
  } finally {
    loading.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(filters, () => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    page.value = 1
    load()
  }, 300)
})

function goTo(p: number) {
  page.value = p
  load()
}

const actions: Record<string, string> = { created: 'создание', updated: 'изменение', deleted: 'удаление' }
const special: Record<string, string> = {
  'auth.login': 'Вход',
  'auth.logout': 'Выход',
  'auth.login_failed': 'Неудачный вход',
  'backup.queued': 'Бекап поставлен в очередь',
  'backup.started': 'Бекап начат',
  'backup.success': 'Бекап выполнен',
  'backup.failed': 'Бекап с ошибкой',
  'backup.deleted': 'Бекап удалён',
  'alert.fired': 'Алерт сработал',
  'alert.resolved': 'Алерт решён',
  'alert.acknowledged': 'Алерт подтверждён',
  'storage_sync.queued': 'Синхронизация хранилища запущена',
  'storage_sync.success': 'Синхронизация хранилища завершена',
  'storage_sync.failed': 'Синхронизация хранилища с ошибкой',
  'api_application.token_issued': 'Токен приложения выпущен',
  'api_application.token_revoked': 'Токен приложения отозван',
  request: 'Действие',
}
const subjects: Record<string, string> = {
  server: 'Сервер',
  database: 'База',
  project: 'Проект',
  project_group: 'Группа проектов',
  backup_storage: 'Хранилище',
  backup_hook: 'Ссылка-хук',
  server_backup_settings: 'Настройки бекапа',
  server_metrics: 'Сбор метрик',
  monitor: 'Монитор',
  alert_channel: 'Канал алертов',
  alert_rule: 'Правило алерта',
  user: 'Пользователь',
  api_application: 'Приложение',
}

function eventLabel(e: AuditLogEntry): string {
  if (special[e.event]) return special[e.event]!
  const [type, action] = e.event.split('.')
  return `${subjects[type!] ?? type}: ${actions[action!] ?? action}`
}

function actorLabel(e: AuditLogEntry): string {
  if (e.actor_type === 'system') return 'Система'
  return `${e.actor_type === 'application' ? 'Приложение' : ''} ${e.actor_name ?? '#' + e.actor_id}`.trim()
}

function subjectText(e: AuditLogEntry): string {
  if (e.event === 'request' && e.changes) return `${e.changes.method} ${e.changes.path}`
  return e.subject_label ?? ''
}

// Срок хранения
const retention = ref<number | null>(null)
const retentionMsg = ref('')
async function saveRetention() {
  if (!retention.value) return
  retentionMsg.value = ''
  try {
    retention.value = (await saveAuditSettings(retention.value)).retention_days
    retentionMsg.value = 'Сохранено'
  } catch (e) {
    retentionMsg.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  }
}

onMounted(() => {
  load()
  auditSettings()
    .then((s) => (retention.value = s.retention_days))
    .catch(() => {})
})
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div class="space-y-0.5">
        <h2 class="text-xl font-semibold tracking-tight">Журнал действий</h2>
        <p class="text-sm text-muted-foreground">Кто и что менял, входы в систему, бекапы и алерты. Секреты скрыты.</p>
      </div>
      <form v-if="retention !== null" class="flex items-center gap-2 text-sm" @submit.prevent="saveRetention">
        <label for="retention" class="text-muted-foreground">Хранить, дней</label>
        <input id="retention" v-model.number="retention" type="number" min="1" max="3650" :class="inputClass" class="w-24" />
        <button type="submit" :class="btnClass.outline">Сохранить</button>
        <span v-if="retentionMsg" class="text-muted-foreground">{{ retentionMsg }}</span>
      </form>
    </header>

    <div class="flex flex-wrap gap-2">
      <select v-model="filters.actor_type" :class="inputClass" class="w-44">
        <option value="">Все акторы</option>
        <option value="user">Пользователи</option>
        <option value="application">Приложения</option>
        <option value="system">Система</option>
      </select>
      <select v-model="filters.event" :class="inputClass" class="w-56">
        <option value="">Все события</option>
        <option value="auth.*">Входы и выходы</option>
        <option value="backup.*">Бекапы</option>
        <option value="alert.*">Алерты</option>
        <option value="request">Действия без изменения данных</option>
        <option value="auth.login_failed">Неудачные входы</option>
      </select>
      <select v-model="filters.subject_type" :class="inputClass" class="w-48">
        <option value="">Все объекты</option>
        <option v-for="(l, k) in subjects" :key="k" :value="k">{{ l }}</option>
      </select>
      <input v-model="filters.from" type="date" :class="inputClass" class="w-40" title="С даты" />
      <input v-model="filters.to" type="date" :class="inputClass" class="w-40" title="По дату" />
      <input v-model="filters.search" type="search" placeholder="Имя актора или объекта" :class="inputClass" class="max-w-xs" />
    </div>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>

    <div class="overflow-x-auto rounded-xl border bg-card">
      <table class="w-full text-sm">
        <thead class="border-b text-left text-muted-foreground">
          <tr>
            <th class="px-4 py-3 font-medium">Время</th>
            <th class="px-4 py-3 font-medium">Кто</th>
            <th class="px-4 py-3 font-medium">Событие</th>
            <th class="px-4 py-3 font-medium">Объект</th>
            <th class="px-4 py-3 font-medium">IP</th>
            <th class="w-10" />
          </tr>
        </thead>
        <tbody class="divide-y">
          <template v-for="e in logs" :key="e.id">
            <tr class="cursor-pointer hover:bg-accent/40" @click="expanded = expanded === e.id ? null : e.id">
              <td class="px-4 py-2.5 whitespace-nowrap text-muted-foreground">{{ formatDate(e.created_at) }}</td>
              <td class="px-4 py-2.5" :class="{ 'text-muted-foreground': e.actor_type === 'system' }">{{ actorLabel(e) }}</td>
              <td class="px-4 py-2.5" :class="{ 'text-destructive': e.event.endsWith('failed') || e.event === 'alert.fired' }">{{ eventLabel(e) }}</td>
              <td class="max-w-xs truncate px-4 py-2.5">{{ subjectText(e) }}</td>
              <td class="px-4 py-2.5 whitespace-nowrap text-muted-foreground">{{ e.ip ?? '' }}</td>
              <td class="px-2 text-muted-foreground"><Icon v-if="e.changes || e.user_agent" name="chevrons" /></td>
            </tr>
            <tr v-if="expanded === e.id">
              <td colspan="6" class="bg-muted/40 px-4 py-3">
                <div class="grid gap-2 text-xs">
                  <div class="text-muted-foreground">
                    <code class="font-mono">{{ e.event }}</code>
                    <span v-if="e.subject_type"> · {{ e.subject_type }} #{{ e.subject_id }}</span>
                    <span v-if="e.user_agent"> · {{ e.user_agent }}</span>
                  </div>
                  <pre v-if="e.changes" class="overflow-x-auto rounded-md border bg-background p-3 font-mono">{{ JSON.stringify(e.changes, null, 2) }}</pre>
                </div>
              </td>
            </tr>
          </template>
          <tr v-if="!logs.length && !loading">
            <td colspan="6" class="px-4 py-10 text-center text-muted-foreground">Записей нет</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between gap-3 text-sm text-muted-foreground">
      <span>Всего: {{ meta.total }}</span>
      <div class="flex items-center gap-2">
        <button :class="btnClass.outline" :disabled="meta.current_page <= 1" @click="goTo(meta.current_page - 1)">Назад</button>
        <span>{{ meta.current_page }} / {{ meta.last_page }}</span>
        <button :class="btnClass.outline" :disabled="meta.current_page >= meta.last_page" @click="goTo(meta.current_page + 1)">Вперёд</button>
      </div>
    </div>
  </div>
</template>
