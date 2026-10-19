<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ApiError } from '@/api/client'
import {
  applicationSections,
  createApplication,
  deleteApplication,
  listApplications,
  regenerateToken,
  revokeToken,
  updateApplication,
  type Application,
  type ScopeSection,
} from '@/api/applications'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { btnClass, formatDate, inputClass } from '@/lib/ui'

type Level = 'none' | 'read' | 'write'

const apps = ref<Application[]>([])
const sections = ref<ScopeSection[]>([])
const loading = ref(false)
const loadError = ref('')

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    ;[apps.value, sections.value] = await Promise.all([listApplications(), sections.value.length ? sections.value : applicationSections()])
  } catch {
    loadError.value = 'Не удалось загрузить приложения'
  } finally {
    loading.value = false
  }
}

/** Права → уровень по разделу ('*' — все разделы) */
function levels(scopes: string[]): Record<string, Level> {
  const result: Record<string, Level> = { '*': 'none' }
  for (const s of sections.value) result[s.key] = 'none'
  for (const scope of scopes) {
    const [section, level] = scope.split(':') as [string, Level]
    if (section in result && (level === 'write' || result[section] === 'none')) result[section] = level
  }
  return result
}

function scopesFrom(map: Record<string, Level>): string[] {
  return Object.entries(map)
    .filter(([, l]) => l !== 'none')
    .map(([s, l]) => `${s}:${l}`)
}

function scopeSummary(app: Application): string {
  const map = levels(app.scopes)
  if (map['*'] === 'write') return 'Полный доступ'
  if (map['*'] === 'read') {
    const writes = sections.value.filter((s) => map[s.key] === 'write').map((s) => s.label)
    return writes.length ? `Чтение всего, изменение: ${writes.join(', ')}` : 'Только чтение'
  }
  const parts = sections.value.filter((s) => map[s.key] !== 'none').map((s) => `${s.label} (${map[s.key] === 'write' ? 'изм.' : 'чт.'})`)
  return parts.length ? parts.join(', ') : 'Нет прав'
}

// Создание и редактирование
const editing = ref<Application | null>(null)
const formOpen = ref(false)
const form = reactive<{ name: string; description: string; is_active: boolean; levels: Record<string, Level> }>({
  name: '',
  description: '',
  is_active: true,
  levels: {},
})
const formErrors = ref<Record<string, string[]>>({})
const formError = ref('')
const saving = ref(false)

function openForm(app: Application | null) {
  editing.value = app
  Object.assign(form, {
    name: app?.name ?? '',
    description: app?.description ?? '',
    is_active: app?.is_active ?? true,
    levels: levels(app?.scopes ?? ['*:read']),
  })
  formErrors.value = {}
  formError.value = ''
  formOpen.value = true
}

// Показ токена после создания или перевыпуска — один раз
const shownToken = ref<{ app: string; token: string } | null>(null)
const copied = ref(false)

async function copyToken() {
  if (!shownToken.value) return
  await navigator.clipboard.writeText(shownToken.value.token)
  copied.value = true
  setTimeout(() => (copied.value = false), 1500)
}

async function save() {
  saving.value = true
  formErrors.value = {}
  formError.value = ''
  const body = { name: form.name, description: form.description || null, is_active: form.is_active, scopes: scopesFrom(form.levels) }
  try {
    if (editing.value) {
      await updateApplication(editing.value.id, body)
    } else {
      const app = await createApplication(body)
      if (app.token) shownToken.value = { app: app.name, token: app.token }
    }
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) formErrors.value = e.errors
    else formError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

async function toggleActive(app: Application) {
  await updateApplication(app.id, { is_active: !app.is_active })
  await load()
}

// Перевыпуск, отзыв и удаление — с подтверждением
const confirming = ref<{ app: Application; action: 'regenerate' | 'revoke' | 'delete' } | null>(null)
const confirmError = ref('')
const confirmText = {
  regenerate: { title: 'Перевыпустить токен?', text: 'Текущий токен сразу перестанет работать — обновите его в интеграции.', button: 'Перевыпустить' },
  revoke: { title: 'Отозвать токен?', text: 'Приложение перестанет авторизоваться, пока вы не выпустите новый токен.', button: 'Отозвать' },
  delete: { title: 'Удалить приложение?', text: 'Токен перестанет работать, записи журнала останутся.', button: 'Удалить' },
}

async function confirmAction() {
  if (!confirming.value) return
  const { app, action } = confirming.value
  confirmError.value = ''
  try {
    if (action === 'regenerate') {
      const res = await regenerateToken(app.id)
      if (res.token) shownToken.value = { app: res.name, token: res.token }
    } else if (action === 'revoke') {
      await revokeToken(app.id)
    } else {
      await deleteApplication(app.id)
    }
    confirming.value = null
    await load()
  } catch (e) {
    confirmError.value = e instanceof ApiError ? e.message : 'Не удалось выполнить'
  }
}

onMounted(load)

const label = 'text-sm leading-none font-medium'
const iconBtn = 'inline-flex size-8 items-center justify-center rounded-md hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-40'
const levelOptions: { value: Level; label: string }[] = [
  { value: 'none', label: 'Нет' },
  { value: 'read', label: 'Чтение' },
  { value: 'write', label: 'Изменение' },
]
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div class="space-y-0.5">
        <h2 class="text-xl font-semibold tracking-tight">Приложения</h2>
        <p class="text-sm text-muted-foreground">
          Интеграции с доступом к API по токену.
          <RouterLink to="/api-docs" class="text-primary underline-offset-4 hover:underline">Документация API</RouterLink>
        </p>
      </div>
      <button :class="btnClass.primary" @click="openForm(null)"><Icon name="plus" />Добавить</button>
    </header>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>

    <div class="overflow-x-auto rounded-xl border bg-card">
      <table class="w-full text-sm">
        <thead class="border-b text-left text-muted-foreground">
          <tr>
            <th class="px-4 py-3 font-medium">Название</th>
            <th class="px-4 py-3 font-medium">Права</th>
            <th class="px-4 py-3 font-medium">Токен</th>
            <th class="px-4 py-3 font-medium">Последний запрос</th>
            <th class="w-40 px-4 py-3" />
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="a in apps" :key="a.id" :class="{ 'opacity-60': !a.is_active }">
            <td class="px-4 py-3">
              <div class="font-medium">
                {{ a.name }}
                <span v-if="!a.is_active" class="ml-1 rounded-md bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">выключено</span>
              </div>
              <div v-if="a.description" class="text-xs text-muted-foreground">{{ a.description }}</div>
            </td>
            <td class="max-w-xs px-4 py-3 text-muted-foreground">{{ scopeSummary(a) }}</td>
            <td class="px-4 py-3 whitespace-nowrap">
              <code v-if="a.has_token" class="font-mono text-xs">{{ a.token_prefix }}…</code>
              <span v-else class="text-destructive">отозван</span>
            </td>
            <td class="px-4 py-3 whitespace-nowrap text-muted-foreground">
              {{ formatDate(a.last_used_at) }}
              <div v-if="a.last_used_ip" class="text-xs">{{ a.last_used_ip }}</div>
            </td>
            <td class="px-4 py-2">
              <div class="flex justify-end gap-1">
                <button :class="iconBtn" :title="a.is_active ? 'Выключить' : 'Включить'" @click="toggleActive(a)"><Icon :name="a.is_active ? 'pause' : 'play'" /></button>
                <button :class="iconBtn" title="Перевыпустить токен" @click="(confirming = { app: a, action: 'regenerate' }), (confirmError = '')"><Icon name="refresh" /></button>
                <button :class="iconBtn" title="Отозвать токен" :disabled="!a.has_token" @click="(confirming = { app: a, action: 'revoke' }), (confirmError = '')"><Icon name="shield" /></button>
                <button :class="iconBtn" title="Изменить" @click="openForm(a)"><Icon name="pencil" /></button>
                <button :class="iconBtn" class="text-destructive" title="Удалить" @click="(confirming = { app: a, action: 'delete' }), (confirmError = '')"><Icon name="trash" /></button>
              </div>
            </td>
          </tr>
          <tr v-if="!apps.length && !loading">
            <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">Приложений пока нет</td>
          </tr>
        </tbody>
      </table>
    </div>

    <Modal v-if="formOpen" :title="editing ? 'Изменить приложение' : 'Новое приложение'" wide @close="formOpen = false">
      <form class="grid gap-4" @submit.prevent="save">
        <div class="grid gap-2">
          <label for="a-name" :class="label">Название</label>
          <input id="a-name" v-model="form.name" required :class="inputClass" :aria-invalid="!!formErrors.name" />
          <p v-for="m in formErrors.name" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid gap-2">
          <label for="a-desc" :class="label">Описание</label>
          <input id="a-desc" v-model="form.description" :class="inputClass" placeholder="Для чего это приложение" />
        </div>
        <div class="grid gap-2">
          <span :class="label">Права</span>
          <div class="divide-y rounded-md border">
            <div v-for="s in [{ key: '*', label: 'Все разделы' }, ...sections]" :key="s.key" class="flex items-center justify-between gap-3 px-3 py-1.5 text-sm">
              <span :class="{ 'font-medium': s.key === '*' }">{{ s.label }}</span>
              <div class="flex gap-0.5 rounded-md bg-muted p-0.5">
                <button
                  v-for="o in levelOptions"
                  :key="o.value"
                  type="button"
                  class="rounded px-2 py-0.5 text-xs"
                  :class="form.levels[s.key] === o.value ? 'bg-background shadow-xs' : 'text-muted-foreground'"
                  @click="form.levels[s.key] = o.value"
                >
                  {{ o.label }}
                </button>
              </div>
            </div>
          </div>
          <p class="text-xs text-muted-foreground">«Чтение» — GET-запросы, «Изменение» — ещё и создание, изменение, удаление и запуск. Право на все разделы складывается с правами отдельных разделов.</p>
          <p v-for="m in formErrors.scopes" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.is_active" type="checkbox" class="size-4 accent-primary" />
          Активно — токен принимается
        </label>
        <p v-if="formError" class="text-sm text-destructive">{{ formError }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" :class="btnClass.outline" @click="formOpen = false">Отмена</button>
          <button type="submit" :disabled="saving" :class="btnClass.primary">{{ editing ? 'Сохранить' : 'Создать' }}</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="shownToken" title="Токен приложения" wide @close="shownToken = null">
      <p class="text-sm text-muted-foreground">
        Токен «{{ shownToken.app }}» показывается <b>один раз</b> — сохраните его. Передавайте заголовком
        <code class="font-mono">Authorization: Bearer …</code> или <code class="font-mono">X-Api-Key: …</code>.
      </p>
      <div class="mt-3 flex items-center gap-2">
        <code class="min-w-0 flex-1 rounded-md border bg-muted px-3 py-2 font-mono text-xs break-all">{{ shownToken.token }}</code>
        <button :class="btnClass.outline" @click="copyToken"><Icon :name="copied ? 'check' : 'copy'" />{{ copied ? 'Скопировано' : 'Копировать' }}</button>
      </div>
      <div class="mt-6 flex justify-end">
        <button :class="btnClass.primary" @click="shownToken = null">Готово</button>
      </div>
    </Modal>

    <Modal v-if="confirming" :title="confirmText[confirming.action].title" @close="confirming = null">
      <p class="text-sm text-muted-foreground">«{{ confirming.app.name }}»: {{ confirmText[confirming.action].text }}</p>
      <p v-if="confirmError" class="mt-3 text-sm text-destructive">{{ confirmError }}</p>
      <div class="mt-6 flex justify-end gap-2">
        <button :class="btnClass.outline" @click="confirming = null">Отмена</button>
        <button :class="confirming.action === 'regenerate' ? btnClass.primary : btnClass.destructive" @click="confirmAction">{{ confirmText[confirming.action].button }}</button>
      </div>
    </Modal>
  </div>
</template>
