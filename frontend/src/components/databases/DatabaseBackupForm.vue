<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { DatabaseConnection, DbSchema, DbTable } from '@/api/databases'
import type { Retention } from '@/api/storages'
import Icon from '@/components/Icon.vue'
import RetentionFields from '@/components/RetentionFields.vue'
import ScheduleFields from '@/components/ScheduleFields.vue'
import SaveBar from '@/components/form/SaveBar.vue'
import SegmentedControl from '@/components/form/SegmentedControl.vue'
import SettingsSection from '@/components/form/SettingsSection.vue'
import { btnClass, formatBytes } from '@/lib/ui'
import { unshownErrors, useUnsavedGuard } from '@/lib/unsaved'

const props = defineProps<{ connection: DatabaseConnection }>()
const emit = defineEmits<{ saved: [connection: DatabaseConnection] }>()

const c = props.connection
const isMysql = c.engine === 'mysql'

type TableMode = 'data' | 'schema' | 'skip'

const form = reactive({
  enabled: false,
  mode: 'all' as 'all' | 'selected',
  databases: [] as string[],
  schedule: '',
  options: {} as DatabaseConnection['backup']['options'],
  retention: { storage_id: null, keep_count: 7, max_size: null } as Retention,
})
/** Режим таблиц «база.таблица»: schema — только структура, skip — пропустить. Нет записи — целиком. */
const tableMode = reactive<Record<string, Exclude<TableMode, 'data'>>>({})

// Сохранённые настройки — от них считаются несохранённые изменения и к ним возвращает «Отменить»
const saved = ref(c)

function fill(conn: DatabaseConnection) {
  saved.value = conn
  form.enabled = conn.backup.enabled
  form.mode = conn.backup.databases.length ? 'selected' : 'all'
  form.databases = [...conn.backup.databases]
  form.schedule = conn.backup.schedule
  form.options = { ...conn.backup.options }
  form.retention = { ...conn.retention }
  for (const k of Object.keys(tableMode)) delete tableMode[k]
  for (const t of conn.backup.exclude_tables) tableMode[t] = 'skip'
  for (const t of conn.backup.schema_only_tables) tableMode[t] = 'schema'
  errors.value = {}
  formError.value = ''
}

function payload() {
  // Настройки таблиц сохраняем только для баз, которые попадают в бекап
  const inDumped = (k: string) => form.mode === 'all' || form.databases.some((d) => k.startsWith(d + '.'))
  const entries = Object.entries(tableMode).filter(([k]) => inDumped(k)).sort()
  return {
    backup_enabled: form.enabled,
    schedule: form.schedule,
    databases: form.mode === 'selected' ? form.databases : [],
    exclude_tables: entries.filter(([, m]) => m === 'skip').map(([k]) => k),
    schema_only_tables: entries.filter(([, m]) => m === 'schema').map(([k]) => k),
    options: form.options,
    ...form.retention,
  }
}

const errors = ref<Record<string, string[]>>({})
const formError = ref('')
fill(c)
const savedSnapshot = ref(JSON.stringify(payload()))
const dirty = computed(() => JSON.stringify(payload()) !== savedSnapshot.value)
const scheduleChanged = computed(() => form.enabled !== saved.value.backup.enabled || form.schedule !== saved.value.backup.schedule)
useUnsavedGuard(dirty)

// Базы на сервере СУБД
const schemas = ref<DbSchema[] | null>(null)
const schemasError = ref('')
const schemasLoading = ref(false)
const showSystem = ref(false)

async function loadSchemas() {
  schemasLoading.value = true
  schemasError.value = ''
  try {
    schemas.value = (await api<{ data: DbSchema[] }>(`/databases/${c.id}/schemas`)).data
  } catch (e) {
    schemasError.value = e instanceof ApiError ? e.message : 'Не удалось получить список баз'
  } finally {
    schemasLoading.value = false
  }
}

const visibleSchemas = computed(() => (schemas.value ?? []).filter((s) => showSystem.value || !s.system || form.databases.includes(s.name)))
const systemCount = computed(() => (schemas.value ?? []).filter((s) => s.system).length)

/** Базы, которые попадут в бекап: выбранные или все несистемные. */
const dumped = computed(() =>
  form.mode === 'selected' ? form.databases : (schemas.value ?? []).filter((s) => !s.system).map((s) => s.name),
)

function toggleDb(name: string) {
  form.databases = form.databases.includes(name) ? form.databases.filter((d) => d !== name) : [...form.databases, name].sort()
}

// Таблицы — загружаются при раскрытии базы
const tables = reactive<Record<string, { loading: boolean; error: string; items: DbTable[] | null; open: boolean }>>({})

async function toggleTables(db: string) {
  // берём объект из reactive-хранилища (а не исходный литерал) — иначе изменения не отрисуются
  tables[db] ??= { loading: false, error: '', items: null, open: false }
  const t = tables[db]!
  t.open = !t.open
  if (!t.open || t.items) return
  t.loading = true
  try {
    t.items = (await api<{ data: DbTable[] }>(`/databases/${c.id}/tables?database=${encodeURIComponent(db)}`)).data
  } catch (e) {
    t.error = e instanceof ApiError ? e.message : 'Не удалось получить таблицы'
  } finally {
    t.loading = false
  }
}

const key = (db: string, table: string) => `${db}.${table}`
const tunedCount = (db: string) => Object.keys(tableMode).filter((k) => k.startsWith(db + '.')).length

function setMode(db: string, table: string, mode: TableMode) {
  if (mode === 'data') delete tableMode[key(db, table)]
  else tableMode[key(db, table)] = mode
}

function setAll(db: string, mode: TableMode) {
  for (const t of tables[db]?.items ?? []) setMode(db, t.name, mode)
}

const tableModes: { value: TableMode; label: string }[] = [
  { value: 'data', label: 'целиком' },
  { value: 'schema', label: 'структура' },
  { value: 'skip', label: 'пропустить' },
]

// Параметры дампа
const lockModes = [
  { value: 'single_transaction', title: 'Снимок транзакции, без блокировки', text: 'Рекомендуется для InnoDB: консистентный дамп, запись в базу не останавливается (--single-transaction). Таблицы MyISAM могут попасть в дамп неконсистентно.' },
  { value: 'lock_tables', title: 'Блокировать таблицы на время дампа', text: 'Для MyISAM и смешанных баз: таблицы каждой базы блокируются на чтение (--lock-tables), запись ждёт окончания дампа этой базы.' },
  { value: 'none', title: 'Без блокировок', text: 'Быстро и ничего не блокирует, но если в базу пишут во время дампа, данные таблиц могут не совпасть между собой (--skip-lock-tables).' },
]
const formatOptions = [
  { value: 'custom', label: 'Custom (pg_restore)' },
  { value: 'plain', label: 'SQL-файл' },
]

const optionsSummary = computed(() => {
  const o = form.options
  const parts = isMysql
    ? [
        lockModes.find((m) => m.value === o.lock_mode)?.title ?? '',
        [o.routines && 'процедуры', o.triggers && 'триггеры', o.events && 'события'].filter(Boolean).join(', '),
      ]
    : [o.format === 'plain' ? 'SQL-файл' : 'формат custom', o.no_owner ? 'без владельцев' : '']
  return [...parts, o.compress ? 'gzip' : 'без сжатия'].filter(Boolean).join(' · ')
})
const optionErrors = computed(() => Object.keys(errors.value).some((k) => k.startsWith('options')))

/** Ошибки элементов списка приходят как `databases.0`… */
const listErrors = (...fields: string[]) =>
  Object.entries(errors.value)
    .filter(([k]) => fields.some((f) => k === f || k.startsWith(`${f}.`)))
    .flatMap(([, v]) => v)

// Сохранение
const saving = ref(false)
const savedNotice = ref(false)
const noDatabases = computed(() => form.mode === 'selected' && !form.databases.length)

async function save() {
  saving.value = true
  savedNotice.value = false
  errors.value = {}
  formError.value = ''
  try {
    const res = await api<{ data: DatabaseConnection }>(`/databases/${c.id}`, { method: 'PATCH', body: payload() })
    fill(res.data)
    savedSnapshot.value = JSON.stringify(payload())
    savedNotice.value = true
    emit('saved', res.data)
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      errors.value = e.errors
      formError.value = unshownErrors(e.errors, ['databases', 'exclude_tables', 'schema_only_tables', 'schedule', 'backup_enabled', 'keep_count', 'max_size', 'options'])
    } else formError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

onMounted(loadSchemas)

const modeOptions = [
  { value: 'all' as const, label: 'Все, кроме служебных' },
  { value: 'selected' as const, label: 'Только выбранные' },
]
const optionCard = 'flex cursor-pointer gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-accent/50'
const check = 'flex items-center gap-2 text-sm'
</script>

<template>
  <form class="grid" @submit.prevent="save">
    <SettingsSection title="Что дампить" description="Каждая база — отдельный файл. Для больших таблиц логов и кешей можно оставить только структуру.">
      <div class="grid gap-2">
        <SegmentedControl v-model="form.mode" :options="modeOptions" />
        <p class="text-xs text-muted-foreground">
          {{ form.mode === 'all' ? 'Новые базы попадут в бекап автоматически.' : 'Отметьте базы, которые нужно дампить.' }}
        </p>
      </div>

      <div v-if="schemasLoading" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Получаем список баз…</div>
      <div v-else-if="schemasError" class="flex flex-wrap items-center gap-3 rounded-lg border border-destructive/40 p-3 text-sm">
        <span class="flex-1 text-destructive">{{ schemasError }}</span>
        <button type="button" :class="btnClass.outline" @click="loadSchemas">Повторить</button>
      </div>
      <div v-else-if="schemas" class="grid gap-2">
        <ul class="divide-y rounded-lg border text-sm">
          <li v-for="s in visibleSchemas" :key="s.name">
            <div class="flex items-center gap-3 px-3 py-2">
              <label class="flex min-w-0 flex-1 items-center gap-2" :class="form.mode === 'selected' && 'cursor-pointer'">
                <input
                  v-if="form.mode === 'selected'"
                  type="checkbox"
                  class="size-4 accent-primary"
                  :checked="form.databases.includes(s.name)"
                  @change="toggleDb(s.name)"
                />
                <Icon v-else :name="s.system ? 'x' : 'check'" class="size-4 shrink-0" :class="s.system ? 'text-muted-foreground' : 'text-primary'" />
                <span class="truncate font-mono text-xs" :class="!dumped.includes(s.name) && 'text-muted-foreground'">{{ s.name }}</span>
                <span v-if="s.system" class="text-xs text-muted-foreground">служебная</span>
              </label>
              <span class="text-xs whitespace-nowrap text-muted-foreground">{{ s.size !== null ? formatBytes(s.size) : '' }}</span>
              <button
                v-if="dumped.includes(s.name)"
                type="button"
                class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs whitespace-nowrap hover:bg-accent"
                :class="tunedCount(s.name) ? 'text-primary' : 'text-muted-foreground'"
                @click="toggleTables(s.name)"
              >
                {{ tunedCount(s.name) ? `Таблицы: изменено ${tunedCount(s.name)}` : 'Таблицы' }}
                <Icon name="chevron-right" class="size-3.5 transition-transform" :class="tables[s.name]?.open && 'rotate-90'" />
              </button>
            </div>
            <div v-if="tables[s.name]?.open" class="border-t bg-muted/30 px-3 py-2">
              <p v-if="tables[s.name]!.loading" class="flex items-center gap-2 text-xs text-muted-foreground"><Icon name="loader" class="size-3.5 animate-spin" />Загрузка таблиц…</p>
              <p v-else-if="tables[s.name]!.error" class="text-xs text-destructive">{{ tables[s.name]!.error }}</p>
              <template v-else-if="tables[s.name]!.items">
                <p v-if="!tables[s.name]!.items!.length" class="text-xs text-muted-foreground">Таблиц нет</p>
                <template v-else>
                  <div class="mb-2 flex flex-wrap items-center gap-x-1 text-xs text-muted-foreground">
                    Для всех таблиц:
                    <button v-for="m in tableModes" :key="m.value" type="button" class="rounded px-1.5 py-0.5 text-foreground hover:bg-accent" @click="setAll(s.name, m.value)">{{ m.label }}</button>
                    <span class="basis-full">«Структура» — таблица создастся при восстановлении, но пустой: удобно для логов, сессий, кешей.</span>
                  </div>
                  <ul class="grid max-h-72 gap-1 overflow-y-auto">
                    <li v-for="t in tables[s.name]!.items!" :key="t.name" class="flex items-center gap-2">
                      <span class="min-w-0 flex-1 truncate font-mono text-xs" :class="tableMode[key(s.name, t.name)] === 'skip' && 'text-muted-foreground line-through'">{{ t.name }}</span>
                      <span class="text-xs whitespace-nowrap text-muted-foreground">
                        <template v-if="t.rows !== null">~{{ t.rows.toLocaleString('ru-RU') }} строк · </template>{{ t.size !== null ? formatBytes(t.size) : '' }}
                      </span>
                      <span class="flex rounded-md border bg-background p-0.5">
                        <button
                          v-for="m in tableModes"
                          :key="m.value"
                          type="button"
                          class="rounded px-2 py-0.5 text-xs"
                          :class="(tableMode[key(s.name, t.name)] ?? 'data') === m.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'"
                          @click="setMode(s.name, t.name, m.value)"
                        >
                          {{ m.label }}
                        </button>
                      </span>
                    </li>
                  </ul>
                </template>
              </template>
            </div>
          </li>
          <li v-if="!visibleSchemas.length" class="p-4 text-center text-muted-foreground">Баз нет</li>
        </ul>
        <label v-if="systemCount" class="flex items-center gap-2 text-xs text-muted-foreground">
          <input v-model="showSystem" type="checkbox" class="size-3.5 accent-primary" />Показать служебные базы ({{ systemCount }})
        </label>
      </div>
      <p v-for="m in listErrors('databases', 'exclude_tables', 'schema_only_tables')" :key="m" class="text-sm text-destructive">{{ m }}</p>
    </SettingsSection>

    <SettingsSection title="Расписание" description="Когда запускать бекап автоматически.">
      <ScheduleFields
        v-model:enabled="form.enabled"
        v-model:schedule="form.schedule"
        :timezone="saved.backup.timezone"
        :next-run-at="saved.backup.next_run_at"
        :changed="scheduleChanged"
        :errors="listErrors('schedule', 'backup_enabled')"
      />
    </SettingsSection>

    <SettingsSection title="Хранение" description="Где лежат дампы и когда удаляются старые.">
      <RetentionFields v-model="form.retention" :errors="errors" />
    </SettingsSection>

    <SettingsSection
      title="Параметры дампа"
      :description="isMysql ? 'Блокировки во время дампа и что ещё включить, кроме таблиц.' : 'Формат файла и сжатие.'"
      collapsible
      :summary="optionsSummary"
      :force-open="optionErrors"
    >
      <template v-if="isMysql">
        <div class="grid gap-2">
          <label v-for="m in lockModes" :key="m.value" :class="optionCard">
            <input v-model="form.options.lock_mode" type="radio" :value="m.value" class="mt-0.5 size-4 accent-primary" />
            <span class="grid gap-0.5">
              <span class="text-sm font-medium">{{ m.title }}</span>
              <span class="text-xs text-muted-foreground">{{ m.text }}</span>
            </span>
          </label>
        </div>
        <div class="flex flex-wrap gap-x-5 gap-y-2">
          <label :class="check"><input v-model="form.options.routines" type="checkbox" class="size-4 accent-primary" />Процедуры и функции</label>
          <label :class="check"><input v-model="form.options.triggers" type="checkbox" class="size-4 accent-primary" />Триггеры</label>
          <label :class="check"><input v-model="form.options.events" type="checkbox" class="size-4 accent-primary" />События (events)</label>
        </div>
      </template>
      <template v-else>
        <p class="text-sm text-muted-foreground">pg_dump всегда делает консистентный снимок и не блокирует запись; на время дампа блокируются только изменения структуры (ALTER, DROP).</p>
        <div class="grid gap-2">
          <span class="text-sm font-medium">Формат</span>
          <SegmentedControl v-model="form.options.format!" :options="formatOptions" />
          <p class="text-xs text-muted-foreground">Custom — сжатый, восстанавливается выборочно через pg_restore. SQL — обычный текст для psql.</p>
        </div>
        <label :class="check"><input v-model="form.options.no_owner" type="checkbox" class="size-4 accent-primary" />Без владельцев и прав — чтобы восстановить под другим пользователем</label>
      </template>
      <label :class="check"><input v-model="form.options.compress" type="checkbox" class="size-4 accent-primary" />Сжимать дамп (gzip)</label>
      <p v-for="m in listErrors('options')" :key="m" class="text-sm text-destructive">{{ m }}</p>
    </SettingsSection>

    <SaveBar :dirty="dirty" :saving="saving" :saved="savedNotice" :error="formError" :disabled-reason="noDatabases ? 'Выберите хотя бы одну базу' : undefined" class="mt-6" @reset="fill(saved)" />
  </form>
</template>
