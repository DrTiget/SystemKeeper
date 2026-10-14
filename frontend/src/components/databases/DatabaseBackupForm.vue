<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { DatabaseConnection, DbSchema, DbTable } from '@/api/databases'
import Icon from '@/components/Icon.vue'
import RetentionFields from '@/components/RetentionFields.vue'
import SchedulePicker from '@/components/SchedulePicker.vue'
import type { Retention } from '@/api/storages'
import { btnClass, formatBytes, formatDate, inputClass } from '@/lib/ui'

const props = defineProps<{ connection: DatabaseConnection }>()
const emit = defineEmits<{ saved: [connection: DatabaseConnection] }>()

const c = props.connection
const isMysql = c.engine === 'mysql'

const retention = ref<Retention>({ ...c.retention })
const form = reactive({
  enabled: c.backup.enabled,
  mode: (c.backup.databases.length ? 'selected' : 'all') as 'all' | 'selected',
  databases: [...c.backup.databases],
  schedule: c.backup.schedule,
  options: { ...c.backup.options },
})
/** Режим каждой таблицы: data — целиком, schema — только структура, skip — пропустить. По умолчанию data. */
const tableMode = reactive<Record<string, 'schema' | 'skip'>>({})
for (const t of c.backup.exclude_tables) tableMode[t] = 'skip'
for (const t of c.backup.schema_only_tables) tableMode[t] = 'schema'

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

function setMode(db: string, table: string, mode: 'data' | 'schema' | 'skip') {
  if (mode === 'data') delete tableMode[key(db, table)]
  else tableMode[key(db, table)] = mode
}

function setAll(db: string, mode: 'data' | 'schema' | 'skip') {
  for (const t of tables[db]?.items ?? []) setMode(db, t.name, mode)
}

// Сохранение
const saving = ref(false)
const savedNotice = ref(false)
const errors = ref<Record<string, string[]>>({})
const formError = ref('')

async function save() {
  saving.value = true
  savedNotice.value = false
  errors.value = {}
  formError.value = ''
  // Настройки таблиц сохраняем только для баз, которые попадают в бекап
  const inDumped = (k: string) => form.mode === 'all' || form.databases.some((d) => k.startsWith(d + '.'))
  const entries = Object.entries(tableMode).filter(([k]) => inDumped(k))
  try {
    const res = await api<{ data: DatabaseConnection }>(`/databases/${c.id}`, {
      method: 'PATCH',
      body: {
        backup_enabled: form.enabled,
        schedule: form.schedule,
        databases: form.mode === 'selected' ? form.databases : [],
        exclude_tables: entries.filter(([, m]) => m === 'skip').map(([k]) => k),
        schema_only_tables: entries.filter(([, m]) => m === 'schema').map(([k]) => k),
        options: form.options,
        ...retention.value,
      },
    })
    savedNotice.value = true
    emit('saved', res.data)
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) errors.value = e.errors
    else formError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

const allErrors = computed(() => Object.values(errors.value).flat())

onMounted(loadSchemas)

const label = 'text-sm leading-none font-medium'
const hint = 'text-sm text-muted-foreground'
const optionCard = 'flex cursor-pointer gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-accent/50'
const mini = 'cursor-pointer rounded px-2 py-0.5 text-xs has-checked:bg-primary has-checked:text-primary-foreground'
const lockModes = [
  { value: 'single_transaction', title: 'Без блокировки — снимок транзакции', text: 'Рекомендуется для InnoDB: консистентный дамп, запись в базу не останавливается (--single-transaction). Таблицы MyISAM в таком режиме могут попасть в дамп неконсистентно.' },
  { value: 'lock_tables', title: 'Блокировать таблицы на время дампа', text: 'Для MyISAM и смешанных баз: таблицы каждой базы блокируются на чтение (--lock-tables), запись ждёт окончания дампа этой базы.' },
  { value: 'none', title: 'Без блокировок', text: 'Быстро и ничего не блокирует, но если в базу пишут во время дампа, данные таблиц могут не совпасть между собой (--skip-lock-tables).' },
]
</script>

<template>
  <form class="grid gap-6" @submit.prevent="save">
    <label class="flex items-center gap-3 rounded-lg border p-3">
      <input v-model="form.enabled" type="checkbox" class="size-4 accent-primary" />
      <span class="grid gap-0.5">
        <span class="text-sm font-medium">Бекап по расписанию</span>
        <span class="text-xs text-muted-foreground">
          <template v-if="connection.backup.enabled && connection.backup.next_run_at">Следующий запуск — {{ formatDate(connection.backup.next_run_at) }}</template>
          <template v-else>Выключен — настройки сохранятся, но бекап не запускается</template>
        </span>
      </span>
    </label>

    <section class="grid gap-3">
      <div class="grid gap-1">
        <span :class="label">Какие базы дампить</span>
        <div class="flex gap-2">
          <label class="flex h-9 flex-1 cursor-pointer items-center justify-center rounded-md border px-2 text-sm has-checked:border-primary has-checked:bg-accent has-checked:text-accent-foreground">
            <input v-model="form.mode" type="radio" value="all" class="sr-only" />Все, кроме служебных
          </label>
          <label class="flex h-9 flex-1 cursor-pointer items-center justify-center rounded-md border px-2 text-sm has-checked:border-primary has-checked:bg-accent has-checked:text-accent-foreground">
            <input v-model="form.mode" type="radio" value="selected" class="sr-only" />Только выбранные
          </label>
        </div>
        <p v-if="form.mode === 'all'" :class="hint">Новые базы попадут в бекап автоматически.</p>
      </div>

      <div v-if="schemasLoading" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Получаем список баз…</div>
      <div v-else-if="schemasError" class="flex flex-wrap items-center gap-3 rounded-md border border-destructive/40 p-3 text-sm">
        <span class="flex-1 text-destructive">{{ schemasError }}</span>
        <button type="button" :class="btnClass.outline" @click="loadSchemas">Повторить</button>
      </div>
      <template v-else-if="schemas">
        <ul class="divide-y rounded-md border text-sm">
          <li v-for="s in visibleSchemas" :key="s.name">
            <div class="flex items-center gap-2 px-3 py-2">
              <input
                v-if="form.mode === 'selected'"
                type="checkbox"
                class="size-4 accent-primary"
                :checked="form.databases.includes(s.name)"
                @change="toggleDb(s.name)"
              />
              <Icon v-else :name="s.system ? 'x' : 'check'" class="size-4" :class="s.system ? 'text-muted-foreground' : 'text-primary'" />
              <span class="font-mono text-xs" :class="!dumped.includes(s.name) && 'text-muted-foreground'">{{ s.name }}</span>
              <span v-if="s.system" class="text-xs text-muted-foreground">служебная</span>
              <span class="ml-auto text-xs text-muted-foreground">{{ s.size !== null ? formatBytes(s.size) : '' }}</span>
              <button
                v-if="dumped.includes(s.name)"
                type="button"
                class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs hover:bg-accent"
                @click="toggleTables(s.name)"
              >
                Таблицы<template v-if="tunedCount(s.name)"> · настроено {{ tunedCount(s.name) }}</template>
                <Icon name="chevron-right" class="size-3.5 transition-transform" :class="tables[s.name]?.open && 'rotate-90'" />
              </button>
            </div>
            <div v-if="tables[s.name]?.open" class="border-t bg-muted/30 px-3 py-2">
              <p v-if="tables[s.name]!.loading" class="flex items-center gap-2 text-xs text-muted-foreground"><Icon name="loader" class="size-3.5 animate-spin" />Загрузка таблиц…</p>
              <p v-else-if="tables[s.name]!.error" class="text-xs text-destructive">{{ tables[s.name]!.error }}</p>
              <template v-else-if="tables[s.name]!.items">
                <p v-if="!tables[s.name]!.items!.length" class="text-xs text-muted-foreground">Таблиц нет</p>
                <template v-else>
                  <div class="mb-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    Всем:
                    <button type="button" class="rounded px-1.5 hover:bg-accent" @click="setAll(s.name, 'data')">целиком</button>
                    <button type="button" class="rounded px-1.5 hover:bg-accent" @click="setAll(s.name, 'schema')">только структура</button>
                    <button type="button" class="rounded px-1.5 hover:bg-accent" @click="setAll(s.name, 'skip')">пропустить</button>
                  </div>
                  <ul class="grid max-h-72 gap-1 overflow-y-auto">
                    <li v-for="t in tables[s.name]!.items!" :key="t.name" class="flex items-center gap-2">
                      <span class="min-w-0 flex-1 truncate font-mono text-xs" :class="tableMode[key(s.name, t.name)] === 'skip' && 'text-muted-foreground line-through'">{{ t.name }}</span>
                      <span class="text-xs whitespace-nowrap text-muted-foreground">
                        <template v-if="t.rows !== null">~{{ t.rows.toLocaleString('ru-RU') }} строк · </template>{{ t.size !== null ? formatBytes(t.size) : '' }}
                      </span>
                      <span class="flex rounded-md border bg-background p-0.5">
                        <label :class="mini"><input type="radio" class="sr-only" :name="key(s.name, t.name)" :checked="!tableMode[key(s.name, t.name)]" @change="setMode(s.name, t.name, 'data')" />целиком</label>
                        <label :class="mini"><input type="radio" class="sr-only" :name="key(s.name, t.name)" :checked="tableMode[key(s.name, t.name)] === 'schema'" @change="setMode(s.name, t.name, 'schema')" />структура</label>
                        <label :class="mini"><input type="radio" class="sr-only" :name="key(s.name, t.name)" :checked="tableMode[key(s.name, t.name)] === 'skip'" @change="setMode(s.name, t.name, 'skip')" />пропустить</label>
                      </span>
                    </li>
                  </ul>
                  <p class="mt-2 text-xs text-muted-foreground">«Структура» — таблица создаётся при восстановлении, но без данных: удобно для логов, сессий, кешей.</p>
                </template>
              </template>
            </div>
          </li>
          <li v-if="!visibleSchemas.length" class="p-4 text-center text-muted-foreground">Баз нет</li>
        </ul>
        <label v-if="systemCount" class="flex items-center gap-2 text-sm text-muted-foreground">
          <input v-model="showSystem" type="checkbox" class="size-4 accent-primary" />Показать служебные базы ({{ systemCount }})
        </label>
      </template>
      <p v-if="form.mode === 'selected' && !form.databases.length" :class="hint">Выберите хотя бы одну базу.</p>
    </section>

    <section class="grid gap-2">
      <span :class="label">Как часто</span>
      <SchedulePicker v-model="form.schedule" :timezone="connection.backup.timezone" :invalid="!!errors.schedule" />
    </section>

    <section class="grid gap-3">
      <span :class="label">Параметры дампа</span>
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
        <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
          <label class="flex items-center gap-2"><input v-model="form.options.routines" type="checkbox" class="size-4 accent-primary" />Процедуры и функции</label>
          <label class="flex items-center gap-2"><input v-model="form.options.triggers" type="checkbox" class="size-4 accent-primary" />Триггеры</label>
          <label class="flex items-center gap-2"><input v-model="form.options.events" type="checkbox" class="size-4 accent-primary" />События (events)</label>
        </div>
      </template>
      <template v-else>
        <p class="rounded-lg border p-3 text-sm text-muted-foreground">
          pg_dump всегда делает консистентный снимок и не блокирует запись; блокируются только изменения структуры таблиц (ALTER, DROP) на время дампа.
        </p>
        <div class="flex gap-2">
          <label class="flex h-9 flex-1 cursor-pointer items-center justify-center rounded-md border px-2 text-sm has-checked:border-primary has-checked:bg-accent has-checked:text-accent-foreground">
            <input v-model="form.options.format" type="radio" value="custom" class="sr-only" />Формат custom (pg_restore)
          </label>
          <label class="flex h-9 flex-1 cursor-pointer items-center justify-center rounded-md border px-2 text-sm has-checked:border-primary has-checked:bg-accent has-checked:text-accent-foreground">
            <input v-model="form.options.format" type="radio" value="plain" class="sr-only" />SQL-файл
          </label>
        </div>
        <p :class="hint">Custom — сжатый, восстанавливается выборочно через pg_restore. SQL — обычный текст, восстанавливается через psql.</p>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.options.no_owner" type="checkbox" class="size-4 accent-primary" />Без владельцев и прав (--no-owner --no-privileges) — для восстановления под другим пользователем</label>
      </template>
      <label class="flex items-center gap-2 text-sm"><input v-model="form.options.compress" type="checkbox" class="size-4 accent-primary" />Сжимать дамп (gzip)</label>
    </section>

    <section class="grid gap-2">
      <span class="text-sm leading-none font-medium">Сколько хранить</span>
      <!-- ошибки полей показываются общим списком ниже -->
      <RetentionFields v-model="retention" />
    </section>

    <div v-if="allErrors.length || formError" class="grid gap-1">
      <p v-for="m in allErrors" :key="m" class="text-sm text-destructive">{{ m }}</p>
      <p v-if="formError" class="text-sm text-destructive">{{ formError }}</p>
    </div>
    <div class="flex items-center gap-3">
      <button type="submit" :disabled="saving || (form.mode === 'selected' && !form.databases.length)" :class="btnClass.primary">Сохранить настройки бекапа</button>
      <span v-if="savedNotice" class="text-sm text-muted-foreground">Сохранено</span>
    </div>
  </form>
</template>
