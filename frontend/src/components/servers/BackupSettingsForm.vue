<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { BackupSettings, PreCommandTemplate } from '@/api/servers'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import SchedulePicker from '@/components/SchedulePicker.vue'
import FileBrowserModal from '@/components/servers/FileBrowserModal.vue'
import { btnClass, formatDate, inputClass } from '@/lib/ui'

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
  pre_command: '',
  pre_command_timeout: 300,
  pre_command_continue_on_error: false,
})
const newExclude = ref('')
const newPath = ref('')

const schedule = ref('0 3 * * *')

function fill(s: BackupSettings) {
  saved.value = s
  form.enabled = s.enabled
  form.paths = [...s.paths]
  form.excludes = [...s.excludes]
  form.pre_command = s.pre_command ?? ''
  form.pre_command_timeout = s.pre_command_timeout
  form.pre_command_continue_on_error = s.pre_command_continue_on_error
  schedule.value = s.schedule
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

onMounted(async () => {
  try {
    const [settings, tpl] = await Promise.all([
      api<{ data: BackupSettings }>(`/servers/${props.serverId}/backup-settings`),
      api<{ data: PreCommandTemplate[] }>('/backup/pre-command-templates'),
    ])
    fill(settings.data)
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
    const res = await api<{ data: BackupSettings }>(`/servers/${props.serverId}/backup-settings`, {
      method: 'PUT',
      body: {
        enabled: form.enabled,
        paths: form.paths,
        excludes: form.excludes,
        schedule: schedule.value,
        pre_command: form.pre_command,
        pre_command_timeout: form.pre_command_timeout,
        pre_command_continue_on_error: form.pre_command_continue_on_error,
      },
    })
    fill(res.data)
    savedNotice.value = true
    emit('saved', res.data)
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) errors.value = e.errors
    else formError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

const label = 'text-sm leading-none font-medium'
const hint = 'text-sm text-muted-foreground'
const segment = 'flex h-9 flex-1 cursor-pointer items-center justify-center rounded-md border px-2 text-sm whitespace-nowrap has-checked:border-primary has-checked:bg-accent has-checked:text-accent-foreground'
const chip = 'inline-flex items-center gap-1 rounded-md border bg-background py-1 pr-1 pl-2 font-mono text-xs'
</script>

<template>
  <p v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>
  <p v-else-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>

  <form v-else class="grid gap-6" @submit.prevent="save">
    <label class="flex items-center gap-3 rounded-lg border p-3">
      <input v-model="form.enabled" type="checkbox" class="size-4 accent-primary" />
      <span class="grid gap-0.5">
        <span class="text-sm font-medium">Бекап по расписанию</span>
        <span class="text-xs text-muted-foreground">
          <template v-if="saved?.enabled && saved.next_run_at">Следующий запуск — {{ formatDate(saved.next_run_at) }}</template>
          <template v-else>Выключен — настройки сохранятся, но бекап не запускается</template>
        </span>
      </span>
    </label>

    <section class="grid gap-2">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="grid gap-1">
          <span :class="label">Что бекапить</span>
          <span :class="hint">Каталоги и файлы на сервере.</span>
        </div>
        <button type="button" :class="btnClass.outline" @click="browserOpen = true"><Icon name="folder" />Выбрать на сервере</button>
      </div>
      <div v-if="form.paths.length" class="flex flex-wrap gap-1.5">
        <span v-for="p in form.paths" :key="p" :class="chip">
          <Icon name="folder" class="size-3.5 text-primary" />{{ p }}
          <button type="button" class="rounded hover:bg-accent" title="Убрать" @click="form.paths = form.paths.filter((x) => x !== p)"><Icon name="x" class="size-3.5" /></button>
        </span>
      </div>
      <p v-else class="rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground">Ничего не выбрано</p>
      <div class="flex gap-2">
        <input v-model="newPath" placeholder="или введите путь вручную: /var/www" spellcheck="false" :class="inputClass" class="font-mono" @keydown.enter.prevent="addPath" />
        <button type="button" :class="btnClass.outline" :disabled="!newPath.trim()" @click="addPath">Добавить</button>
      </div>
      <p v-for="m in listErrors('paths')" :key="m" class="text-sm text-destructive">{{ m }}</p>
    </section>

    <section class="grid gap-2">
      <div class="grid gap-1">
        <span :class="label">Исключения</span>
        <span :class="hint">Что не попадёт в бекап: путь или маска — <code class="font-mono text-xs">*.log</code>, <code class="font-mono text-xs">node_modules</code>, <code class="font-mono text-xs">/var/www/storage/cache</code>.</span>
      </div>
      <div v-if="form.excludes.length" class="flex flex-wrap gap-1.5">
        <span v-for="x in form.excludes" :key="x" :class="chip">
          {{ x }}
          <button type="button" class="rounded hover:bg-accent" title="Убрать" @click="form.excludes = form.excludes.filter((e) => e !== x)"><Icon name="x" class="size-3.5" /></button>
        </span>
      </div>
      <div class="flex gap-2">
        <input v-model="newExclude" placeholder="*.log" spellcheck="false" :class="inputClass" class="font-mono" @keydown.enter.prevent="addExclude" />
        <button type="button" :class="btnClass.outline" :disabled="!newExclude.trim()" @click="addExclude">Добавить</button>
      </div>
      <p v-for="m in listErrors('excludes')" :key="m" class="text-sm text-destructive">{{ m }}</p>
    </section>

    <section class="grid gap-2">
      <span :class="label">Как часто</span>
      <SchedulePicker v-model="schedule" :timezone="saved?.timezone" :invalid="!!errors.schedule" />
      <p v-for="m in errors.schedule" :key="m" class="text-sm text-destructive">{{ m }}</p>
    </section>

    <section class="grid gap-2">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="grid gap-1">
          <label for="b-pre" :class="label">Перед бекапом выполнить</label>
          <span :class="hint">Необязательно. Команда выполняется на сервере по SSH, например дамп базы в один из выбранных каталогов.</span>
        </div>
        <button type="button" :class="btnClass.outline" @click="templatesOpen = true"><Icon name="terminal" />Шаблоны</button>
      </div>
      <textarea
        id="b-pre"
        v-model="form.pre_command"
        rows="4"
        spellcheck="false"
        placeholder="mysqldump --single-transaction app > /var/backups/app.sql"
        :class="inputClass"
        class="h-auto py-2 font-mono text-xs"
        :aria-invalid="!!errors.pre_command"
      />
      <p v-for="m in errors.pre_command" :key="m" class="text-sm text-destructive">{{ m }}</p>
      <template v-if="form.pre_command.trim()">
        <div class="flex items-center gap-2 text-sm whitespace-nowrap">
          Ждать не дольше
          <input v-model.number="form.pre_command_timeout" type="number" min="10" max="86400" required :class="inputClass" class="w-24!" />
          секунд
        </div>
        <p v-for="m in errors.pre_command_timeout" :key="m" class="text-sm text-destructive">{{ m }}</p>
        <span :class="label" class="mt-1">Если команда завершилась с ошибкой</span>
        <div class="flex flex-wrap gap-2">
          <label :class="segment"><input v-model="form.pre_command_continue_on_error" type="radio" :value="false" class="sr-only" />Не делать бекап</label>
          <label :class="segment"><input v-model="form.pre_command_continue_on_error" type="radio" :value="true" class="sr-only" />Всё равно сделать бекап</label>
        </div>
        <p :class="hint">
          {{ form.pre_command_continue_on_error ? 'Бекап выполнится, ошибка команды будет записана в результат.' : 'Запуск будет отмечен как неудачный, каталоги не копируются.' }}
        </p>
      </template>
    </section>

    <p v-if="formError" class="text-sm text-destructive">{{ formError }}</p>
    <div class="flex items-center gap-3">
      <button type="submit" :disabled="saving" :class="btnClass.primary">Сохранить настройки бекапа</button>
      <span v-if="savedNotice" class="text-sm text-muted-foreground">Сохранено</span>
    </div>
  </form>

  <FileBrowserModal v-if="browserOpen" :server-id="serverId" :selected="form.paths" title="Что бекапить" @close="browserOpen = false" @apply="applyBrowser" />

  <Modal v-if="templatesOpen" wide title="Шаблоны команд" @close="templatesOpen = false">
    <div class="grid max-h-[60svh] gap-4 overflow-y-auto">
      <p :class="hint">Шаблон добавится в поле команды — поправьте имена баз, контейнеров и пути под свой сервер.</p>
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
