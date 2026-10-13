<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import SelectMenu from '@/components/SelectMenu.vue'
import { buildCron, parseCron, WEEKDAYS } from '@/lib/cron'
import { btnClass, inputClass } from '@/lib/ui'

export interface BackupSettings {
  enabled: boolean
  paths: string[]
  excludes: string[]
  schedule: string
  timezone: string
  next_run_at: string | null
  pre_command: string | null
  pre_command_timeout: number
}

const props = defineProps<{ server: { id: number; name: string } }>()
const emit = defineEmits<{ close: []; saved: [settings: BackupSettings] }>()

const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const formError = ref('')
const timezone = ref('')

const form = reactive({
  enabled: false,
  paths: [''] as string[],
  excludes: [] as string[],
  pre_command: '',
  pre_command_timeout: 300,
})

// Расписание: пресет + время
type Kind = 'hourly' | 'daily' | 'weekly' | 'custom'
const schedule = reactive({ kind: 'daily' as Kind, time: '03:00', minute: 0, weekday: 1, cron: '0 3 * * *' })
const weekdayOptions = [1, 2, 3, 4, 5, 6, 0].map((d) => ({ value: d, label: WEEKDAYS[d]! }))

const cron = computed(() => {
  const [h, m] = schedule.time.split(':').map(Number) as [number, number]
  switch (schedule.kind) {
    case 'hourly':
      return buildCron({ kind: 'hourly', minute: schedule.minute })
    case 'daily':
      return buildCron({ kind: 'daily', hour: h, minute: m })
    case 'weekly':
      return buildCron({ kind: 'weekly', weekday: schedule.weekday, hour: h, minute: m })
    default:
      return buildCron({ kind: 'custom', cron: schedule.cron })
  }
})

function applySchedule(value: string) {
  const p = parseCron(value)
  schedule.cron = value
  schedule.kind = p.kind
  if (p.kind === 'hourly') schedule.minute = p.minute
  if (p.kind === 'daily' || p.kind === 'weekly') schedule.time = `${String(p.hour).padStart(2, '0')}:${String(p.minute).padStart(2, '0')}`
  if (p.kind === 'weekly') schedule.weekday = p.weekday
}

function fill(s: BackupSettings) {
  form.enabled = s.enabled
  form.paths = s.paths.length ? [...s.paths] : ['']
  form.excludes = [...s.excludes]
  form.pre_command = s.pre_command ?? ''
  form.pre_command_timeout = s.pre_command_timeout
  timezone.value = s.timezone
  applySchedule(s.schedule)
}

onMounted(async () => {
  try {
    fill((await api<{ data: BackupSettings }>(`/servers/${props.server.id}/backup-settings`)).data)
  } catch {
    loadError.value = 'Не удалось загрузить настройки'
  } finally {
    loading.value = false
  }
})

/** Ошибки элементов списка приходят как `paths.0`, `paths.1`… */
function itemErrors(field: string, i: number) {
  return errors.value[`${field}.${i}`] ?? []
}

async function save() {
  saving.value = true
  errors.value = {}
  formError.value = ''
  try {
    const res = await api<{ data: BackupSettings }>(`/servers/${props.server.id}/backup-settings`, {
      method: 'PUT',
      body: {
        enabled: form.enabled,
        paths: form.paths.map((p) => p.trim()).filter(Boolean),
        excludes: form.excludes.map((p) => p.trim()).filter(Boolean),
        schedule: cron.value,
        pre_command: form.pre_command,
        pre_command_timeout: form.pre_command_timeout,
      },
    })
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
const iconBtn = 'inline-flex size-9 shrink-0 items-center justify-center rounded-md hover:bg-accent hover:text-accent-foreground'
</script>

<template>
  <Modal wide :title="`Бекап: ${server.name}`" @close="emit('close')">
    <p v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>
    <p v-else-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>

    <form v-else class="grid gap-5" @submit.prevent="save">
      <label class="flex items-center gap-2 text-sm font-medium">
        <input v-model="form.enabled" type="checkbox" class="size-4 accent-primary" />
        Бекап включён
      </label>

      <div class="grid gap-2">
        <span :class="label">Каталоги</span>
        <p :class="hint">Абсолютные пути на сервере, которые попадут в бекап.</p>
        <div v-for="(_, i) in form.paths" :key="i" class="grid gap-1">
          <div class="flex gap-2">
            <input v-model="form.paths[i]" placeholder="/var/www" :class="inputClass" class="font-mono" :aria-invalid="!!itemErrors('paths', i).length" />
            <button type="button" :class="iconBtn" title="Убрать" @click="form.paths.splice(i, 1)"><Icon name="x" /></button>
          </div>
          <p v-for="m in itemErrors('paths', i)" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <p v-for="m in errors.paths" :key="m" class="text-sm text-destructive">{{ m }}</p>
        <button type="button" :class="btnClass.outline" class="justify-self-start" @click="form.paths.push('')"><Icon name="plus" />Каталог</button>
      </div>

      <div class="grid gap-2">
        <span :class="label">Исключения</span>
        <p :class="hint">Что не бекапить: путь или маска, например <code class="font-mono text-xs">*.log</code> или <code class="font-mono text-xs">/var/www/cache</code>.</p>
        <div v-for="(_, i) in form.excludes" :key="i" class="grid gap-1">
          <div class="flex gap-2">
            <input v-model="form.excludes[i]" placeholder="*.log" :class="inputClass" class="font-mono" :aria-invalid="!!itemErrors('excludes', i).length" />
            <button type="button" :class="iconBtn" title="Убрать" @click="form.excludes.splice(i, 1)"><Icon name="x" /></button>
          </div>
          <p v-for="m in itemErrors('excludes', i)" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <button type="button" :class="btnClass.outline" class="justify-self-start" @click="form.excludes.push('')"><Icon name="plus" />Исключение</button>
      </div>

      <div class="grid gap-2">
        <span :class="label">Как часто</span>
        <div class="flex flex-wrap gap-2">
          <label :class="segment"><input v-model="schedule.kind" type="radio" value="hourly" class="sr-only" />Каждый час</label>
          <label :class="segment"><input v-model="schedule.kind" type="radio" value="daily" class="sr-only" />Каждый день</label>
          <label :class="segment"><input v-model="schedule.kind" type="radio" value="weekly" class="sr-only" />Раз в неделю</label>
          <label :class="segment"><input v-model="schedule.kind" type="radio" value="custom" class="sr-only" />Cron</label>
        </div>
        <div v-if="schedule.kind === 'hourly'" class="flex items-center gap-2 text-sm">
          В
          <input v-model.number="schedule.minute" type="number" min="0" max="59" required :class="inputClass" class="w-20" />
          минут каждого часа
        </div>
        <div v-else-if="schedule.kind === 'daily'" class="flex items-center gap-2 text-sm">
          В <input v-model="schedule.time" type="time" required :class="inputClass" class="w-32" />
        </div>
        <div v-else-if="schedule.kind === 'weekly'" class="flex items-center gap-2 text-sm">
          <SelectMenu v-model="schedule.weekday" :options="weekdayOptions" class="w-48" />
          в <input v-model="schedule.time" type="time" required :class="inputClass" class="w-32" />
        </div>
        <div v-else class="grid gap-1">
          <input v-model="schedule.cron" required placeholder="0 3 * * *" :class="inputClass" class="font-mono" :aria-invalid="!!errors.schedule" />
          <p :class="hint">Минута, час, день месяца, месяц, день недели.</p>
        </div>
        <p :class="hint">Время — {{ timezone }}.</p>
        <p v-for="m in errors.schedule" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>

      <div class="grid gap-2">
        <label for="b-pre" :class="label">Перед бекапом выполнить</label>
        <p :class="hint">Необязательно. Команда выполняется на сервере по SSH; если она завершится с ошибкой, бекап не делается. Например, выгрузить дамп базы в один из каталогов.</p>
        <textarea
          id="b-pre"
          v-model="form.pre_command"
          rows="3"
          spellcheck="false"
          placeholder="mysqldump --single-transaction app > /var/backups/app.sql"
          :class="inputClass"
          class="h-auto py-2 font-mono text-xs"
          :aria-invalid="!!errors.pre_command"
        />
        <p v-for="m in errors.pre_command" :key="m" class="text-sm text-destructive">{{ m }}</p>
        <div v-if="form.pre_command.trim()" class="flex items-center gap-2 text-sm whitespace-nowrap">
          Ждать не дольше
          <input v-model.number="form.pre_command_timeout" type="number" min="10" max="86400" required :class="inputClass" class="w-24" />
          секунд
        </div>
        <p v-for="m in errors.pre_command_timeout" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>

      <p v-if="formError" class="text-sm text-destructive">{{ formError }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" :class="btnClass.outline" @click="emit('close')">Отмена</button>
        <button type="submit" :disabled="saving" :class="btnClass.primary">Сохранить</button>
      </div>
    </form>
  </Modal>
</template>
