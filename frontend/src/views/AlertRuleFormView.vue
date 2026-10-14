<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ALERT_KINDS,
  type AlertChannel,
  type AlertKind,
  type AlertRule,
  type AlertRuleInput,
  CHANNEL_TYPES,
  describeCondition,
  OBJECT_LABELS,
  type ObjectType,
  SEVERITY,
  type Severity,
} from '@/api/alerts'
import { api, ApiError } from '@/api/client'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import ProjectSelect from '@/components/ProjectSelect.vue'
import ChannelFormModal from '@/components/alerts/ChannelFormModal.vue'
import SaveBar from '@/components/form/SaveBar.vue'
import SegmentedControl from '@/components/form/SegmentedControl.vue'
import SettingsSection from '@/components/form/SettingsSection.vue'
import ToggleSwitch from '@/components/form/ToggleSwitch.vue'
import { btnClass, inputClass } from '@/lib/ui'
import { unshownErrors, useUnsavedGuard } from '@/lib/unsaved'
import { useProjectsStore } from '@/stores/projects'

const route = useRoute()
const router = useRouter()
const id = route.params.id ? Number(route.params.id) : null
const projects = useProjectsStore()

const initialKind = (route.query.kind as AlertKind) in ALERT_KINDS ? (route.query.kind as AlertKind) : 'monitor_down'
const defaults = (kind: AlertKind): AlertRuleInput => ({
  name: '',
  enabled: true,
  kind,
  ...ALERT_KINDS[kind].defaults,
  project_id: route.query.project_id ? Number(route.query.project_id) : null,
  targets: [],
  channel_ids: [],
  repeat_every: null,
  notify_resolved: true,
})
const form = reactive<AlertRuleInput>(defaults(initialKind))
const rule = ref<AlertRule | null>(null)
const loading = ref(!!id)
const loadError = ref('')
const savedSnapshot = ref('')
const errors = ref<Record<string, string[]>>({})
const formError = ref('')
const saving = ref(false)
const savedNotice = ref(false)

const payload = () => ({ ...form, name: form.name.trim() })
const dirty = computed(() => !loading.value && JSON.stringify(payload()) !== savedSnapshot.value)
useUnsavedGuard(dirty)

function fill(r: AlertRuleInput) {
  const keys = Object.keys(defaults(r.kind)) as (keyof AlertRuleInput)[]
  Object.assign(form, JSON.parse(JSON.stringify(Object.fromEntries(keys.map((k) => [k, r[k]])))))
  savedSnapshot.value = JSON.stringify(payload())
}

// Каналы
const channels = ref<AlertChannel[]>([])
const channelFormOpen = ref(false)
function onChannelCreated(c: AlertChannel) {
  channels.value.push(c)
  form.channel_ids.push(c.id)
  channelFormOpen.value = false
}

onMounted(async () => {
  channels.value = await api<{ data: AlertChannel[] }>('/alert-channels').then((r) => r.data).catch(() => [])
  if (!id) {
    // по умолчанию — во все включённые каналы
    form.channel_ids = channels.value.filter((c) => c.enabled).map((c) => c.id)
    savedSnapshot.value = JSON.stringify(payload())
    projects.crumbs = [{ label: 'Алерты', to: '/alerts/rules' }, { label: 'Новое правило' }]
    loadObjects()
    return
  }
  try {
    rule.value = (await api<{ data: AlertRule }>(`/alert-rules/${id}`)).data
    fill(rule.value)
    projects.crumbs = [{ label: 'Алерты', to: '/alerts/rules' }, { label: rule.value.name }]
    loadObjects()
  } catch (e) {
    loadError.value = e instanceof ApiError && e.status === 404 ? 'Правило не найдено' : 'Не удалось загрузить правило'
  } finally {
    loading.value = false
  }
})

// Смена типа: подставляем пороги по умолчанию, выбранные объекты другого типа сбрасываем
const kind = computed(() => ALERT_KINDS[form.kind])
function setKind(k: AlertKind) {
  if (k === form.kind) return
  const sameObjects = ALERT_KINDS[k].objects.join() === kind.value.objects.join()
  form.kind = k
  Object.assign(form, ALERT_KINDS[k].defaults)
  if (!sameObjects) form.targets = []
}
const kindGroups = computed(() => {
  const groups = new Map<string, AlertKind[]>()
  for (const k of Object.keys(ALERT_KINDS) as AlertKind[]) groups.set(ALERT_KINDS[k].group, [...(groups.get(ALERT_KINDS[k].group) ?? []), k])
  return [...groups.entries()]
})

// Объекты области: серверы, мониторы, базы или хранилища выбранного проекта
const objects = ref<{ type: ObjectType; id: number; name: string; hint?: string }[]>([])
const objectsLoading = ref(false)
const PATHS: Record<ObjectType, string> = { server: '/servers', monitor: '/monitors', database: '/databases', storage: '/storages' }
async function loadObjects() {
  objectsLoading.value = true
  const qs = form.project_id ? `?project_id=${form.project_id}&per_page=100` : '?per_page=100'
  const lists = await Promise.all(
    kind.value.objects.map((t) =>
      api<{ data: { id: number; name: string; host?: string; url?: string | null }[] }>(PATHS[t] + (t === 'storage' ? '' : qs))
        .then((r) => r.data.map((o) => ({ type: t, id: o.id, name: o.name, hint: o.host ?? o.url ?? undefined })))
        .catch(() => []),
    ),
  )
  objects.value = lists.flat()
  objectsLoading.value = false
}
watch(() => [form.project_id, kind.value.objects.join()], loadObjects)

/** «Только выбранные» без единой галочки — тоже режим выбора, а не «все» */
const pickMode = ref(false)
const objectMode = computed({
  get: () => (form.targets.length || pickMode.value ? 'selected' : 'all'),
  set: (v: string) => {
    pickMode.value = v === 'selected'
    if (v === 'all') form.targets = []
  },
})
const objectModeOptions = computed(() => [
  { value: 'all', label: `Все ${kind.value.objects.map((t) => OBJECT_LABELS[t].many).join(' и ')}` },
  { value: 'selected', label: 'Только выбранные' },
])
const isTarget = (o: { type: ObjectType; id: number }) => form.targets.some((t) => t.type === o.type && t.id === o.id)
function toggleObject(o: { type: ObjectType; id: number }) {
  form.targets = isTarget(o) ? form.targets.filter((t) => !(t.type === o.type && t.id === o.id)) : [...form.targets, { type: o.type, id: o.id }]
}

const severityOptions = (Object.keys(SEVERITY) as Severity[]).map((s) => ({ value: s, label: SEVERITY[s].label }))
const REPEATS = [null, 30, 60, 240, 1440]
const repeatOptions = REPEATS.map((v) => ({ value: v ?? 0, label: v === null ? 'Один раз' : v < 60 ? `${v} мин` : v === 1440 ? 'раз в сутки' : `${v / 60} ч` }))
const repeat = computed({ get: () => form.repeat_every ?? 0, set: (v: number) => (form.repeat_every = v || null) })

const autoName = computed(() => describeCondition(form))

async function save() {
  saving.value = true
  errors.value = {}
  formError.value = ''
  savedNotice.value = false
  const body = { ...payload(), name: form.name.trim() || autoName.value }
  try {
    const res = (await api<{ data: AlertRule }>(id ? `/alert-rules/${id}` : '/alert-rules', { method: id ? 'PATCH' : 'POST', body })).data
    rule.value = res
    fill(res)
    savedNotice.value = true
    if (!id) router.replace({ name: 'alert-rules' })
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      errors.value = e.errors
      formError.value = unshownErrors(e.errors, ['threshold', 'duration', 'targets', 'channel_ids', 'name'])
    } else formError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

const deleteOpen = ref(false)
async function destroy() {
  await api(`/alert-rules/${id}`, { method: 'DELETE' })
  savedSnapshot.value = JSON.stringify(payload())
  router.push({ name: 'alert-rules' })
}

const err = (k: string) => Object.entries(errors.value).filter(([key]) => key === k || key.startsWith(`${k}.`)).flatMap(([, v]) => v)
const field = 'grid gap-1.5'
</script>

<template>
  <div class="flex max-w-5xl flex-col gap-6 p-4">
    <RouterLink :to="{ name: 'alert-rules' }" class="inline-flex items-center gap-1.5 self-start text-sm text-muted-foreground hover:text-foreground">
      <Icon name="arrow-left" class="size-4" />Правила
    </RouterLink>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>
    <p v-else-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>

    <template v-else>
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="space-y-1">
          <h2 class="text-xl font-semibold tracking-tight">{{ id ? rule?.name : 'Новое правило' }}</h2>
          <p class="text-sm text-muted-foreground">Что отслеживать, при каком условии и кому сообщать.</p>
        </div>
        <button v-if="id" :class="btnClass.destructive" @click="deleteOpen = true"><Icon name="trash" />Удалить</button>
      </header>

      <form class="rounded-xl border bg-card p-6 shadow-xs" @submit.prevent="save">
        <SettingsSection title="Что отслеживать">
          <div v-for="[group, kinds] in kindGroups" :key="group" class="grid gap-2">
            <span class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ group }}</span>
            <div class="grid gap-2 sm:grid-cols-2">
              <button
                v-for="k in kinds"
                :key="k"
                type="button"
                role="radio"
                :aria-checked="form.kind === k"
                class="grid gap-0.5 rounded-lg border p-3 text-left transition-colors"
                :class="form.kind === k ? 'border-primary bg-accent/50' : 'hover:border-primary/50'"
                @click="setKind(k)"
              >
                <span class="text-sm font-medium">{{ ALERT_KINDS[k].label }}</span>
                <span class="text-xs text-muted-foreground">{{ ALERT_KINDS[k].hint }}</span>
              </button>
            </div>
          </div>
        </SettingsSection>

        <SettingsSection title="Условие" description="Когда поднимать тревогу и насколько это серьёзно.">
          <div v-if="kind.thresholdLabel" class="flex flex-wrap items-center gap-2 text-sm">
            {{ kind.thresholdLabel }}
            <input v-model.number="form.threshold" type="number" :min="kind.min" :max="kind.max" :step="kind.step ?? 1" required aria-label="Порог" :class="inputClass" class="w-24!" :aria-invalid="!!err('threshold').length" />
            {{ kind.unit }}
          </div>
          <p v-for="m in err('threshold')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          <div v-if="kind.duration" class="grid gap-1.5">
            <div class="flex flex-wrap items-center gap-2 text-sm">
              {{ kind.thresholdLabel ? 'и это держится' : 'Сработать, если длится' }}
              <input v-model.number="form.duration" type="number" min="0" max="1440" required aria-label="Минут" :class="inputClass" class="w-20!" />
              мин
            </div>
            <p class="text-xs text-muted-foreground">{{ form.duration ? 'Короткие всплески не поднимут тревогу.' : 'Сработает сразу, при первой же проверке.' }}</p>
            <p v-for="m in err('duration')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div :class="field">
            <span class="text-sm font-medium">Серьёзность</span>
            <SegmentedControl v-model="form.severity" :options="severityOptions" />
          </div>
        </SettingsSection>

        <SettingsSection title="Где" description="Проект и объекты, к которым применяется правило.">
          <div :class="field" class="max-w-sm">
            <label for="r-project" class="text-sm font-medium">Проект</label>
            <ProjectSelect id="r-project" v-model="form.project_id" none-label="Все проекты" />
          </div>
          <div :class="field">
            <SegmentedControl v-model="objectMode" :options="objectModeOptions" />
            <p v-if="objectMode === 'all'" class="text-xs text-muted-foreground">Новые объекты попадут под правило автоматически.</p>
          </div>
          <template v-if="objectMode === 'selected'">
            <p v-if="objectsLoading" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>
            <ul v-else-if="objects.length" class="grid max-h-72 divide-y overflow-y-auto rounded-lg border text-sm">
              <li v-for="o in objects" :key="`${o.type}-${o.id}`">
                <label class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-accent/40">
                  <input type="checkbox" class="size-4 accent-primary" :checked="isTarget(o)" @change="toggleObject(o)" />
                  <Icon :name="OBJECT_LABELS[o.type].icon" class="size-4 text-muted-foreground" />
                  <span class="truncate">{{ o.name }}</span>
                  <span v-if="o.hint" class="ml-auto truncate font-mono text-xs text-muted-foreground">{{ o.hint }}</span>
                </label>
              </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">В этой области ничего нет.</p>
          </template>
          <p v-for="m in err('targets')" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </SettingsSection>

        <SettingsSection title="Кому сообщать" description="Каналы, напоминания и сообщение о том, что всё снова в порядке.">
          <div :class="field">
            <div v-if="channels.length" class="grid gap-2 sm:grid-cols-2">
              <label v-for="c in channels" :key="c.id" class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-accent/50">
                <input v-model="form.channel_ids" type="checkbox" :value="c.id" class="size-4 accent-primary" />
                <Icon :name="CHANNEL_TYPES[c.type].icon" class="size-4 text-muted-foreground" />
                <span class="grid min-w-0">
                  <span class="truncate text-sm font-medium">{{ c.name }}</span>
                  <span class="text-xs text-muted-foreground">{{ CHANNEL_TYPES[c.type].label }}<template v-if="!c.enabled"> · выключен</template></span>
                </span>
              </label>
            </div>
            <p v-if="!form.channel_ids.length" class="text-sm text-amber-600 dark:text-amber-400">Без каналов алерт будет виден только в SystemKeeper.</p>
            <button type="button" class="inline-flex items-center gap-1.5 self-start text-sm font-medium text-primary hover:underline" @click="channelFormOpen = true"><Icon name="plus" class="size-4" />Новый канал</button>
            <p v-for="m in err('channel_ids')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div :class="field">
            <span class="text-sm font-medium">Напоминать, пока не решено</span>
            <SegmentedControl v-model="repeat" :options="repeatOptions" />
            <p class="text-xs text-muted-foreground">Напоминания прекращаются, когда алерт отмечен «Принято».</p>
          </div>
          <ToggleSwitch v-model="form.notify_resolved" label="Сообщить, когда всё в порядке" />
        </SettingsSection>

        <SettingsSection title="Название" description="Как правило подписано в списках и уведомлениях.">
          <div :class="field">
            <input v-model="form.name" :placeholder="autoName" aria-label="Название" :class="inputClass" class="max-w-md" :aria-invalid="!!err('name').length" />
            <p class="text-xs text-muted-foreground">Пусто — «{{ autoName }}».</p>
            <p v-for="m in err('name')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <ToggleSwitch v-model="form.enabled" label="Правило включено" />
        </SettingsSection>

        <SaveBar v-if="id" :dirty="dirty" :saving="saving" :saved="savedNotice" :error="formError" class="mt-6" @reset="rule && fill(rule)" />
        <div v-else class="-mx-6 mt-6 -mb-6 flex flex-wrap items-center justify-end gap-3 rounded-b-xl border-t px-6 py-3">
          <span v-if="formError" class="min-w-0 flex-1 text-sm whitespace-pre-wrap text-destructive">{{ formError }}</span>
          <RouterLink :to="{ name: 'alert-rules' }" :class="btnClass.outline">Отмена</RouterLink>
          <button type="submit" :class="btnClass.primary" :disabled="saving"><Icon v-if="saving" name="loader" class="animate-spin" />Создать правило</button>
        </div>
      </form>
    </template>

    <ChannelFormModal v-if="channelFormOpen" :channel="null" @close="channelFormOpen = false" @saved="onChannelCreated" />
    <Modal v-if="deleteOpen && rule" title="Удалить правило?" @close="deleteOpen = false">
      <p class="text-sm text-muted-foreground">«{{ rule.name }}» перестанет отслеживаться. История срабатываний останется.</p>
      <div class="mt-6 flex justify-end gap-2">
        <button :class="btnClass.outline" @click="deleteOpen = false">Отмена</button>
        <button :class="btnClass.destructive" @click="destroy">Удалить</button>
      </div>
    </Modal>
  </div>
</template>
