<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { BackupJob, BackupType, Paginated, Server, StorageTarget } from '@/api/types'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import Pagination from '@/components/Pagination.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { isActive, statusBadge, typeLabel } from '@/lib/backup'
import { describeCron } from '@/lib/cron'
import { btnClass, errorMessage, formatBytes, formatDate, inputClass } from '@/lib/format'

const emit = defineEmits<{ history: [jobId: number]; changed: [] }>()

const items = ref<BackupJob[]>([])
const meta = ref({ current_page: 1, last_page: 1, per_page: 10, total: 0 })
const loading = ref(true)
const loadError = ref('')
const servers = ref<Server[]>([])
const targets = ref<StorageTarget[]>([])

async function load(page = meta.value.current_page, silent = false) {
  if (!silent) loading.value = true
  loadError.value = ''
  try {
    const res = await api<Paginated<BackupJob>>(`/backup-jobs?page=${page}&per_page=10`)
    if (!res.data.length && page > res.meta.last_page && res.meta.last_page > 0) return load(res.meta.last_page, silent)
    items.value = res.data
    meta.value = res.meta
  } catch (e) {
    if (!silent) loadError.value = errorMessage(e, 'Не удалось загрузить задания')
  } finally {
    loading.value = false
  }
}

let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  load(1)
  api<Paginated<Server>>('/servers?per_page=100').then((r) => (servers.value = r.data)).catch(() => {})
  api<Paginated<StorageTarget>>('/storage-targets?per_page=100').then((r) => (targets.value = r.data)).catch(() => {})
  timer = setInterval(() => {
    if (items.value.some((j) => isActive(j.latest_backup?.status))) load(meta.value.current_page, true)
  }, 4000)
})
onUnmounted(() => clearInterval(timer))

const replace = (j: BackupJob) => {
  const i = items.value.findIndex((x) => x.id === j.id)
  if (i >= 0) items.value[i] = j
}
const cronText = (j: BackupJob) => (j.schedule ? (describeCron(j.schedule) ?? 'Нестандартное расписание') : 'Только вручную')

// --- форма ---
interface Form {
  name: string
  server_id: number | null
  type: BackupType
  schedule: string
  enabled: boolean
  compression_level: number
  retention_count: string
  retention_days: string
  storage_target_ids: number[]
  paths: string
  excludes: string
  db_mode: 'ssh' | 'direct'
  db_host: string
  db_port: string
  db_name: string
  db_user: string
  db_password: string
  db_ssl_verify: boolean
}
const editing = ref<'new' | BackupJob | null>(null)
const form = ref<Form>(emptyForm())
const saving = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

function emptyForm(): Form {
  return {
    name: '', server_id: servers.value[0]?.id ?? null, type: 'files', schedule: '0 3 * * *', enabled: true, compression_level: 3,
    retention_count: '', retention_days: '', storage_target_ids: [], paths: '', excludes: '',
    db_mode: 'ssh', db_host: '', db_port: '', db_name: '', db_user: '', db_password: '', db_ssl_verify: true,
  }
}

function openForm(j: 'new' | BackupJob) {
  fieldErrors.value = {}
  formError.value = ''
  form.value =
    j === 'new'
      ? emptyForm()
      : {
          name: j.name, server_id: j.server_id, type: j.type, schedule: j.schedule ?? '', enabled: j.enabled,
          compression_level: j.compression_level,
          retention_count: j.retention_count?.toString() ?? '', retention_days: j.retention_days?.toString() ?? '',
          storage_target_ids: [...(j.storage_target_ids ?? [])],
          paths: (j.paths ?? []).join('\n'), excludes: (j.excludes ?? []).join('\n'),
          db_mode: j.db_mode ?? 'ssh', db_host: j.db_host ?? '', db_port: j.db_port?.toString() ?? '',
          db_name: j.db_name ?? '', db_user: j.db_user ?? '', db_password: '', db_ssl_verify: j.db_ssl_verify,
        }
  editing.value = j
}

const lines = (s: string) => s.split('\n').map((x) => x.trim()).filter(Boolean)
const intOrNull = (s: string) => (s.trim() === '' ? null : Number(s))
const err = (k: string) => fieldErrors.value[k] ?? []

async function save() {
  saving.value = true
  formError.value = ''
  fieldErrors.value = {}
  const f = form.value
  const body: Record<string, unknown> = {
    name: f.name,
    server_id: f.server_id,
    schedule: f.schedule.trim() || null,
    enabled: f.enabled,
    compression_level: f.compression_level,
    retention_count: intOrNull(f.retention_count),
    retention_days: intOrNull(f.retention_days),
    storage_target_ids: f.storage_target_ids,
  }
  if (editing.value === 'new') body.type = f.type
  if (f.type === 'files') {
    body.paths = lines(f.paths)
    body.excludes = lines(f.excludes)
  } else {
    body.db_mode = f.db_mode
    body.db_host = f.db_host.trim() || null
    body.db_port = intOrNull(f.db_port)
    body.db_name = f.db_name.trim() || null
    body.db_user = f.db_user.trim() || null
    body.db_ssl_verify = f.db_ssl_verify
    if (f.db_password) body.db_password = f.db_password // пусто = не менять
  }
  try {
    const target = editing.value
    if (target === 'new') await api('/backup-jobs', { method: 'POST', body })
    else if (target) await api(`/backup-jobs/${target.id}`, { method: 'PUT', body })
    editing.value = null
    form.value.db_password = ''
    await load(target === 'new' ? 1 : meta.value.current_page)
    emit('changed')
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      fieldErrors.value = e.errors
      formError.value = Object.keys(e.errors).length ? '' : errorMessage(e)
    } else formError.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}

// --- вкл/выкл, запуск ---
const actionError = ref('')
const running = ref<number | null>(null)
async function toggle(j: BackupJob) {
  actionError.value = ''
  try {
    replace((await api<{ data: BackupJob }>(`/backup-jobs/${j.id}`, { method: 'PATCH', body: { enabled: !j.enabled } })).data)
  } catch (e) {
    actionError.value = errorMessage(e, 'Не удалось изменить состояние')
  }
}
async function runNow(j: BackupJob) {
  actionError.value = ''
  running.value = j.id
  try {
    await api(`/backup-jobs/${j.id}/run`, { method: 'POST' })
    await load(meta.value.current_page, true)
  } catch (e) {
    actionError.value = e instanceof ApiError && e.status === 409 ? 'Бекап этого задания уже выполняется или стоит в очереди' : errorMessage(e, 'Не удалось запустить бекап')
  } finally {
    running.value = null
  }
}

// --- удаление ---
const removing = ref<BackupJob | null>(null)
const removeLoading = ref(false)
const removeError = ref('')
async function remove() {
  if (!removing.value) return
  removeLoading.value = true
  removeError.value = ''
  try {
    await api(`/backup-jobs/${removing.value.id}`, { method: 'DELETE' })
    removing.value = null
    await load()
    emit('changed')
  } catch (e) {
    removeError.value = e instanceof ApiError && e.status === 409 ? 'Нельзя удалить: у задания выполняется бекап' : errorMessage(e)
  } finally {
    removeLoading.value = false
  }
}

const toggleTarget = (id: number) => {
  const ids = form.value.storage_target_ids
  const i = ids.indexOf(id)
  if (i >= 0) ids.splice(i, 1)
  else ids.push(id)
}
const label = 'text-sm leading-none font-medium'
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex justify-end">
      <button :class="btnClass.default" @click="openForm('new')"><Icon name="plus" />Добавить задание</button>
    </div>
    <div v-if="actionError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ actionError }}</div>
    <div v-if="loadError" role="alert" class="flex items-center gap-3 rounded-lg border bg-card px-4 py-3 text-sm text-destructive">
      {{ loadError }}
      <button :class="[btnClass.outlineSm, 'ml-auto']" @click="load()">Повторить</button>
    </div>
    <div v-else-if="loading && !items.length" class="rounded-xl border p-10 text-center text-sm text-muted-foreground">Загрузка…</div>
    <div v-else-if="!items.length" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">Заданий пока нет. Создайте первое задание бекапа.</div>
    <template v-else>
      <div class="grid gap-4 lg:grid-cols-2" :class="loading && 'opacity-60'">
        <div v-for="j in items" :key="j.id" class="flex min-w-0 flex-col gap-3 rounded-xl border bg-card p-4" :class="!j.enabled && 'opacity-70'">
          <div class="flex items-center gap-2">
            <Icon name="archive" class="text-muted-foreground" />
            <span class="truncate font-mono font-medium">{{ j.name }}</span>
            <span class="text-xs text-muted-foreground uppercase">{{ typeLabel[j.type] }}</span>
            <span class="ml-auto flex shrink-0">
              <button :class="btnClass.ghostSm" title="Редактировать" @click="openForm(j)"><Icon name="pencil" /></button>
              <button :class="btnClass.ghostSm" title="Удалить" @click="removeError = ''; removing = j"><Icon name="trash" /></button>
            </span>
          </div>
          <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            <dt class="text-muted-foreground">Сервер</dt>
            <dd class="truncate">{{ j.server?.name ?? '—' }}</dd>
            <dt class="text-muted-foreground">Расписание</dt>
            <dd class="min-w-0">
              <template v-if="j.schedule"><span class="font-mono">{{ j.schedule }}</span> <span class="text-muted-foreground">— {{ cronText(j) }}</span></template>
              <span v-else class="text-muted-foreground">{{ cronText(j) }}</span>
            </dd>
            <dt class="text-muted-foreground">Следующий</dt>
            <dd>{{ j.enabled ? formatDate(j.next_run_at) : 'выключено' }}</dd>
            <dt class="text-muted-foreground">Бекапов</dt>
            <dd>{{ j.backups_count }}</dd>
          </dl>
          <div class="flex items-center gap-2 text-sm">
            <template v-if="j.latest_backup">
              <StatusBadge :tone="statusBadge(j.latest_backup.status).tone" :label="statusBadge(j.latest_backup.status).label" />
              <span class="truncate text-xs text-muted-foreground">{{ formatBytes(j.latest_backup.size_bytes) }}</span>
              <span class="ml-auto shrink-0 text-xs text-muted-foreground">{{ formatDate(j.latest_backup.started_at ?? j.latest_backup.created_at) }}</span>
            </template>
            <span v-else class="text-xs text-muted-foreground">Бекапов ещё не было</span>
          </div>
          <div class="flex flex-wrap gap-2">
            <button :class="btnClass.outlineSm" :disabled="running === j.id || isActive(j.latest_backup?.status)" @click="runNow(j)">
              <Icon :name="running === j.id ? 'loader' : 'play'" :class="running === j.id && 'animate-spin'" />Запустить сейчас
            </button>
            <button :class="btnClass.outlineSm" @click="emit('history', j.id)"><Icon name="history" />История</button>
            <button :class="btnClass.outlineSm" @click="toggle(j)">{{ j.enabled ? 'Выключить' : 'Включить' }}</button>
          </div>
        </div>
      </div>
      <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="load" />
    </template>

    <Modal v-if="editing" wide :title="editing === 'new' ? 'Новое задание' : 'Редактировать задание'" @close="editing = null">
      <form class="grid gap-4" @submit.prevent="save">
        <div v-if="formError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ formError }}</div>
        <div class="grid gap-2">
          <label for="j-name" :class="label">Название</label>
          <input id="j-name" v-model="form.name" required maxlength="255" :class="inputClass" :aria-invalid="!!err('name').length" />
          <p v-for="m in err('name')" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="grid gap-2">
            <label for="j-server" :class="label">Сервер</label>
            <select id="j-server" v-model="form.server_id" required :class="inputClass" :aria-invalid="!!err('server_id').length">
              <option v-for="s in servers" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
            <p v-for="m in err('server_id')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div class="grid gap-2">
            <label for="j-type" :class="label">Тип</label>
            <select id="j-type" v-model="form.type" :disabled="editing !== 'new'" :class="inputClass">
              <option v-for="(l, t) in typeLabel" :key="t" :value="t">{{ l }}</option>
            </select>
            <p v-for="m in err('type')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
        </div>

        <template v-if="form.type === 'files'">
          <div class="grid gap-2">
            <label for="j-paths" :class="label">Пути</label>
            <textarea id="j-paths" v-model="form.paths" required rows="3" placeholder="/var/www&#10;/etc/nginx" class="font-mono" :class="[inputClass, 'min-h-20 py-2']" :aria-invalid="!!err('paths').length" />
            <p class="text-xs text-muted-foreground">Абсолютные пути, по одному в строке.</p>
            <p v-for="m in [...err('paths'), ...Object.entries(fieldErrors).filter(([k]) => k.startsWith('paths.')).flatMap(([, v]) => v)]" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div class="grid gap-2">
            <label for="j-excl" :class="label">Исключения</label>
            <textarea id="j-excl" v-model="form.excludes" rows="2" placeholder="*.log&#10;node_modules" class="font-mono" :class="[inputClass, 'min-h-16 py-2']" />
            <p class="text-xs text-muted-foreground">Шаблоны для tar --exclude, по одному в строке.</p>
            <p v-for="m in [...err('excludes'), ...Object.entries(fieldErrors).filter(([k]) => k.startsWith('excludes.')).flatMap(([, v]) => v)]" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
        </template>
        <template v-else>
          <div class="grid gap-2">
            <span :class="label">Режим подключения</span>
            <div class="flex gap-2">
              <button type="button" :class="form.db_mode === 'ssh' ? btnClass.default : btnClass.outline" @click="form.db_mode = 'ssh'">Через SSH</button>
              <button type="button" :class="form.db_mode === 'direct' ? btnClass.default : btnClass.outline" @click="form.db_mode = 'direct'">Напрямую</button>
            </div>
            <p class="text-xs text-muted-foreground">
              {{ form.db_mode === 'ssh' ? 'Дамп снимается командой на сервере по SSH.' : 'SystemKeeper сам подключается к БД: нужны хост и пользователь.' }}
            </p>
            <p v-for="m in err('db_mode')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
            <div class="grid gap-2">
              <label for="j-dbhost" :class="label">Хост БД</label>
              <input id="j-dbhost" v-model="form.db_host" :required="form.db_mode === 'direct'" placeholder="127.0.0.1" class="font-mono" :class="inputClass" :aria-invalid="!!err('db_host').length" />
              <p v-for="m in err('db_host')" :key="m" class="text-sm text-destructive">{{ m }}</p>
            </div>
            <div class="grid gap-2">
              <label for="j-dbport" :class="label">Порт</label>
              <input id="j-dbport" v-model="form.db_port" type="number" min="1" max="65535" :placeholder="form.type === 'mysql' ? '3306' : '5432'" :class="inputClass" :aria-invalid="!!err('db_port').length" />
              <p v-for="m in err('db_port')" :key="m" class="text-sm text-destructive">{{ m }}</p>
            </div>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
              <label for="j-dbuser" :class="label">Пользователь</label>
              <input id="j-dbuser" v-model="form.db_user" :required="form.db_mode === 'direct'" autocomplete="off" class="font-mono" :class="inputClass" :aria-invalid="!!err('db_user').length" />
              <p v-for="m in err('db_user')" :key="m" class="text-sm text-destructive">{{ m }}</p>
            </div>
            <div class="grid gap-2">
              <label for="j-dbpass" :class="label">Пароль</label>
              <input
                id="j-dbpass" v-model="form.db_password" type="password" autocomplete="new-password" class="font-mono" :class="inputClass"
                :placeholder="editing !== 'new' && editing.has_db_password ? 'Оставьте пустым, чтобы не менять' : ''" :aria-invalid="!!err('db_password').length"
              />
              <p v-for="m in err('db_password')" :key="m" class="text-sm text-destructive">{{ m }}</p>
            </div>
          </div>
          <div class="grid gap-2">
            <label for="j-dbname" :class="label">База данных</label>
            <input id="j-dbname" v-model="form.db_name" placeholder="Пусто — все базы" class="font-mono" :class="inputClass" :aria-invalid="!!err('db_name').length" />
            <p v-for="m in err('db_name')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <label v-if="form.type === 'mysql' && form.db_mode === 'direct'" class="flex items-start gap-2 text-sm">
            <input v-model="form.db_ssl_verify" type="checkbox" class="mt-0.5 size-4 rounded-[4px] border-input accent-primary" />
            <span>Проверять TLS-сертификат MySQL<span class="block text-xs text-muted-foreground">Отключите для самоподписанных сертификатов.</span></span>
          </label>
        </template>

        <div class="grid gap-4 sm:grid-cols-2">
          <div class="grid gap-2">
            <label for="j-cron" :class="label">Расписание (cron)</label>
            <input id="j-cron" v-model="form.schedule" placeholder="0 3 * * *" class="font-mono" :class="inputClass" :aria-invalid="!!err('schedule').length" />
            <p class="text-xs text-muted-foreground">
              <template v-if="!form.schedule.trim()">Пусто — только ручной запуск.</template>
              <template v-else>{{ describeCron(form.schedule) ?? 'Минуты часы день месяц день_недели' }}</template>
            </p>
            <p v-for="m in err('schedule')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div class="grid gap-2">
            <label for="j-comp" :class="label">Сжатие zstd: {{ form.compression_level }}</label>
            <input id="j-comp" v-model.number="form.compression_level" type="range" min="1" max="19" class="h-9 accent-primary" />
            <p v-for="m in err('compression_level')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="grid gap-2">
            <label for="j-rc" :class="label">Хранить последних, шт.</label>
            <input id="j-rc" v-model="form.retention_count" type="number" min="1" placeholder="Без ограничения" :class="inputClass" :aria-invalid="!!err('retention_count').length" />
            <p v-for="m in err('retention_count')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div class="grid gap-2">
            <label for="j-rd" :class="label">Хранить, дней</label>
            <input id="j-rd" v-model="form.retention_days" type="number" min="1" placeholder="Без ограничения" :class="inputClass" :aria-invalid="!!err('retention_days').length" />
            <p v-for="m in err('retention_days')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
        </div>
        <div class="grid gap-2">
          <span :class="label">Хранилища</span>
          <p v-if="!targets.length" class="text-sm text-muted-foreground">Хранилищ нет — добавьте их в разделе «Хранилища».</p>
          <label v-for="t in targets" :key="t.id" class="flex items-center gap-2 text-sm">
            <input type="checkbox" :checked="form.storage_target_ids.includes(t.id)" class="size-4 rounded-[4px] border-input accent-primary" @change="toggleTarget(t.id)" />
            <span class="font-mono">{{ t.name }}</span>
            <span class="text-xs text-muted-foreground">{{ t.type === 'local' ? 'локальный каталог' : 'Яндекс.Диск' }}{{ t.enabled ? '' : ' · выключено' }}</span>
          </label>
          <p v-for="m in [...err('storage_target_ids'), ...Object.entries(fieldErrors).filter(([k]) => k.startsWith('storage_target_ids.')).flatMap(([, v]) => v)]" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.enabled" type="checkbox" class="size-4 rounded-[4px] border-input accent-primary" />Включено
        </label>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" :class="btnClass.outline" @click="editing = null">Отмена</button>
          <button type="submit" :class="btnClass.default" :disabled="saving">
            <Icon v-if="saving" name="loader" class="animate-spin" />Сохранить
          </button>
        </div>
      </form>
    </Modal>

    <ConfirmDialog
      v-if="removing"
      title="Удалить задание?"
      :text="`Задание «${removing.name}» будет удалено вместе с ${removing.backups_count} бекапами и их файлами.`"
      :loading="removeLoading"
      :error="removeError"
      @confirm="remove"
      @close="removing = null"
    />
  </div>
</template>
