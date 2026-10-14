<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { BackupSettings, PreCommandTemplate } from '@/api/servers'
import type { Retention } from '@/api/storages'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import RetentionFields from '@/components/RetentionFields.vue'
import ScheduleFields from '@/components/ScheduleFields.vue'
import SaveBar from '@/components/form/SaveBar.vue'
import SegmentedControl from '@/components/form/SegmentedControl.vue'
import SettingsSection from '@/components/form/SettingsSection.vue'
import FileBrowserModal from '@/components/servers/FileBrowserModal.vue'
import { btnClass, inputClass } from '@/lib/ui'
import { unshownErrors, useUnsavedGuard } from '@/lib/unsaved'

const props = defineProps<{ serverId: number }>()
const emit = defineEmits<{ saved: [settings: BackupSettings] }>()

const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const saved = ref<BackupSettings | null>(null)
const savedNotice = ref(false)
const errors = ref<Record<string, string[]>>({})
const formError = ref('')

const form = reactive({
  enabled: false,
  paths: [] as string[],
  excludes: [] as string[],
  schedule: '0 3 * * *',
  pre_command: '',
  pre_command_timeout: 300,
  pre_command_continue_on_error: false,
  retention: { storage_id: null, keep_count: 7, max_size: null } as Retention,
})
const newExclude = ref('')
const newPath = ref('')

function fill(s: BackupSettings) {
  saved.value = s
  form.enabled = s.enabled
  form.paths = [...s.paths]
  form.excludes = [...s.excludes]
  form.schedule = s.schedule
  form.pre_command = s.pre_command ?? ''
  form.pre_command_timeout = s.pre_command_timeout
  form.pre_command_continue_on_error = s.pre_command_continue_on_error
  form.retention = { ...s.retention }
  errors.value = {}
  formError.value = ''
}

const payload = () => {
  const { retention, ...rest } = form
  return { ...rest, ...retention }
}
// Снимок сохранённого состояния: сравниваем с ним, чтобы понять, есть ли несохранённые изменения
const savedSnapshot = ref('')
const dirty = computed(() => !loading.value && !loadError.value && JSON.stringify(payload()) !== savedSnapshot.value)
const scheduleChanged = computed(() => form.enabled !== saved.value?.enabled || form.schedule !== saved.value?.schedule)
useUnsavedGuard(dirty)

function reset() {
  if (saved.value) fill(saved.value)
}

// Каталоги: файловый менеджер и ручной ввод
const browserOpen = ref(false)

/** Убираем пути, которые уже входят в выбранные родительские каталоги. */
function compact(paths: string[]) {
  const unique = [...new Set(paths.map((p) => p.trim()).filter(Boolean))].sort()
  return unique.filter((p) => !unique.some((q) => q !== p && (q === '/' || p.startsWith(q + '/'))))
}

function applyBrowser(paths: string[]) {
  form.paths = compact(paths)
  browserOpen.value = false
}

function addPath() {
  if (!newPath.value.trim()) return
  form.paths = compact([...form.paths, newPath.value])
  newPath.value = ''
}

function addExclude() {
  const v = newExclude.value.trim()
  if (v && !form.excludes.includes(v)) form.excludes.push(v)
  newExclude.value = ''
}

// Шаблоны команды перед бекапом
const templates = ref<PreCommandTemplate[]>([])
const templatesOpen = ref(false)
const templateGroups = computed(() => {
  const groups = new Map<string, PreCommandTemplate[]>()
  for (const t of templates.value) groups.set(t.group, [...(groups.get(t.group) ?? []), t])
  return [...groups.entries()]
})

function useTemplate(t: PreCommandTemplate) {
  form.pre_command = form.pre_command.trim() ? `${form.pre_command.trim()}\n${t.command}` : t.command
  templatesOpen.value = false
}

const commandSummary = computed(() => {
  const lines = form.pre_command.trim().split('\n').filter(Boolean)
  if (!lines.length) return 'Не задана'
  return lines.length > 1 ? `${lines[0]} … (+${lines.length - 1})` : lines[0]!
})
const commandErrors = computed(() => !!(errors.value.pre_command || errors.value.pre_command_timeout))

onMounted(async () => {
  try {
    const [settings, tpl] = await Promise.all([
      api<{ data: BackupSettings }>(`/servers/${props.serverId}/backup-settings`),
      api<{ data: PreCommandTemplate[] }>('/backup/pre-command-templates'),
    ])
    fill(settings.data)
    savedSnapshot.value = JSON.stringify(payload())
    templates.value = tpl.data
  } catch {
    loadError.value = 'Не удалось загрузить настройки бекапа'
  } finally {
    loading.value = false
  }
})

/** Ошибки элементов списка приходят как `paths.0`, `paths.1`… */
const listErrors = (field: string) =>
  Object.entries(errors.value)
    .filter(([k]) => k === field || k.startsWith(`${field}.`))
    .flatMap(([, v]) => v)

async function save() {
  saving.value = true
  errors.value = {}
  formError.value = ''
  savedNotice.value = false
  try {
    const res = await api<{ data: BackupSettings }>(`/servers/${props.serverId}/backup-settings`, { method: 'PUT', body: payload() })
    fill(res.data)
    savedSnapshot.value = JSON.stringify(payload())
    savedNotice.value = true
    emit('saved', res.data)
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      errors.value = e.errors
      formError.value = unshownErrors(e.errors, ['paths', 'excludes', 'schedule', 'keep_count', 'max_size', 'pre_command', 'pre_command_timeout'])
    } else formError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

const chip = 'inline-flex items-center gap-1 rounded-md border bg-background py-1 pr-1 pl-2 font-mono text-xs'
const onErrorOptions = [
  { value: false, label: 'Отменить бекап' },
  { value: true, label: 'Всё равно сделать бекап' },
]
</script>

<template>
  <p v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>
  <p v-else-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>

  <form v-else class="grid" @submit.prevent="save">
    <SettingsSection title="Что копировать" description="Каталоги и файлы на сервере, которые попадут в архив.">
      <div class="grid gap-2">
        <ul v-if="form.paths.length" class="divide-y rounded-lg border text-sm">
          <li v-for="p in form.paths" :key="p" class="flex items-center gap-2 py-1.5 pr-1.5 pl-3">
            <Icon name="folder" class="size-4 shrink-0 text-primary" />
            <span class="min-w-0 flex-1 truncate font-mono text-xs">{{ p }}</span>
            <button type="button" class="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground" title="Убрать" @click="form.paths = form.paths.filter((x) => x !== p)">
              <Icon name="x" class="size-4" />
            </button>
          </li>
        </ul>
        <p v-else class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">Пока ничего не выбрано</p>
        <div class="flex flex-wrap gap-2">
          <button type="button" :class="btnClass.outline" @click="browserOpen = true"><Icon name="folder" />Выбрать на сервере</button>
          <div class="flex min-w-60 flex-1 gap-2">
            <input v-model="newPath" placeholder="или путь вручную: /var/www" spellcheck="false" aria-label="Путь на сервере" :class="inputClass" class="font-mono" @keydown.enter.prevent="addPath" />
            <button v-if="newPath.trim()" type="button" :class="btnClass.outline" @click="addPath"><Icon name="plus" />Добавить</button>
          </div>
        </div>
        <p v-for="m in listErrors('paths')" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>

      <div class="grid gap-2">
        <span class="text-sm font-medium">Исключить</span>
        <div v-if="form.excludes.length" class="flex flex-wrap gap-1.5">
          <span v-for="x in form.excludes" :key="x" :class="chip">
            {{ x }}
            <button type="button" class="rounded hover:bg-accent" title="Убрать" @click="form.excludes = form.excludes.filter((e) => e !== x)"><Icon name="x" class="size-3.5" /></button>
          </span>
        </div>
        <div class="flex max-w-md gap-2">
          <input v-model="newExclude" placeholder="*.log, node_modules, /var/www/storage/cache" spellcheck="false" aria-label="Исключение" :class="inputClass" class="font-mono" @keydown.enter.prevent="addExclude" />
          <button v-if="newExclude.trim()" type="button" :class="btnClass.outline" @click="addExclude"><Icon name="plus" />Добавить</button>
        </div>
        <p class="text-xs text-muted-foreground">Путь или маска. Enter — добавить.</p>
        <p v-for="m in listErrors('excludes')" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
    </SettingsSection>

    <SettingsSection title="Расписание" description="Когда запускать бекап автоматически.">
      <ScheduleFields
        v-model:enabled="form.enabled"
        v-model:schedule="form.schedule"
        :timezone="saved?.timezone"
        :next-run-at="saved?.next_run_at"
        :changed="scheduleChanged"
        :errors="errors.schedule"
      />
    </SettingsSection>

    <SettingsSection title="Хранение" description="Где лежат архивы и когда удаляются старые.">
      <RetentionFields v-model="form.retention" :errors="errors" />
    </SettingsSection>

    <SettingsSection
      title="Команда перед бекапом"
      description="Необязательно. Выполняется на сервере по SSH — например, дамп базы в один из выбранных каталогов."
      collapsible
      :summary="commandSummary"
      :force-open="commandErrors"
    >
      <div class="grid gap-2">
        <div class="flex justify-end">
          <button type="button" :class="btnClass.outline" @click="templatesOpen = true"><Icon name="terminal" />Вставить шаблон</button>
        </div>
        <textarea
          v-model="form.pre_command"
          rows="4"
          spellcheck="false"
          aria-label="Команда перед бекапом"
          placeholder="mysqldump --single-transaction app > /var/backups/app.sql"
          :class="inputClass"
          class="h-auto py-2 font-mono text-xs"
          :aria-invalid="!!errors.pre_command"
        />
        <p v-for="m in errors.pre_command" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
      <template v-if="form.pre_command.trim()">
        <div class="grid gap-1.5">
          <div class="flex flex-wrap items-center gap-2 text-sm">
            Ждать не дольше
            <input v-model.number="form.pre_command_timeout" type="number" min="10" max="86400" required aria-label="Тайм-аут, секунд" :class="inputClass" class="w-24!" />
            секунд
          </div>
          <p v-for="m in errors.pre_command_timeout" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid gap-2">
          <span class="text-sm font-medium">Если команда завершилась с ошибкой</span>
          <SegmentedControl v-model="form.pre_command_continue_on_error" :options="onErrorOptions" />
          <p class="text-xs text-muted-foreground">
            {{ form.pre_command_continue_on_error ? 'Каталоги всё равно скопируются, ошибка команды попадёт в журнал бекапа.' : 'Запуск будет отмечен как неудачный, каталоги не копируются.' }}
          </p>
        </div>
      </template>
    </SettingsSection>

    <SaveBar :dirty="dirty" :saving="saving" :saved="savedNotice" :error="formError" class="mt-6" @reset="reset" />
  </form>

  <FileBrowserModal v-if="browserOpen" :server-id="serverId" :selected="form.paths" title="Что копировать" @close="browserOpen = false" @apply="applyBrowser" />

  <Modal v-if="templatesOpen" wide title="Шаблоны команд" @close="templatesOpen = false">
    <div class="grid max-h-[60svh] gap-4 overflow-y-auto">
      <p class="text-sm text-muted-foreground">Шаблон добавится в поле команды — поправьте имена баз, контейнеров и пути под свой сервер.</p>
      <div v-for="[group, items] in templateGroups" :key="group" class="grid gap-2">
        <span class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ group }}</span>
        <button
          v-for="t in items"
          :key="t.key"
          type="button"
          class="grid gap-1 rounded-lg border p-3 text-left hover:border-primary hover:bg-accent/50"
          @click="useTemplate(t)"
        >
          <span class="text-sm font-medium">{{ t.title }}</span>
          <span class="text-xs text-muted-foreground">{{ t.description }}</span>
          <code class="truncate font-mono text-xs text-muted-foreground">{{ t.command }}</code>
        </button>
      </div>
    </div>
  </Modal>
</template>
