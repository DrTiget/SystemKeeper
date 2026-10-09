<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { CheckResult, Paginated, Server, SshKey } from '@/api/types'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import Pagination from '@/components/Pagination.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { btnClass, errorMessage, formatDate, inputClass, textareaClass } from '@/lib/format'

const items = ref<Server[]>([])
const meta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
const loading = ref(true)
const loadError = ref('')

async function load(page = meta.value.current_page) {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api<Paginated<Server>>(`/servers?page=${page}&per_page=15`)
    if (!res.data.length && page > res.meta.last_page && res.meta.last_page > 0) return load(res.meta.last_page)
    items.value = res.data
    meta.value = res.meta
  } catch (e) {
    loadError.value = errorMessage(e, 'Не удалось загрузить список серверов')
  } finally {
    loading.value = false
  }
}
onMounted(() => load(1))

function replace(s: Server) {
  const i = items.value.findIndex((x) => x.id === s.id)
  if (i >= 0) items.value[i] = s
}

const status = (s: Server) =>
  s.last_check_status === 'ok'
    ? ({ tone: 'success', label: 'Доступен' } as const)
    : s.last_check_status === 'failed'
      ? ({ tone: 'danger', label: 'Ошибка' } as const)
      : ({ tone: 'neutral', label: 'Не проверялся' } as const)

// --- создание / редактирование ---
const keys = ref<SshKey[]>([])
const editing = ref<'new' | Server | null>(null)
const form = ref({ name: '', host: '', port: 22, username: '', auth_type: 'key', ssh_key_id: '' as number | '', password: '', description: '' })
const saving = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

async function loadKeys() {
  try {
    keys.value = (await api<Paginated<SshKey>>('/ssh-keys?per_page=100')).data
  } catch {
    keys.value = []
  }
}

async function openForm(target: 'new' | Server) {
  fieldErrors.value = {}
  formError.value = ''
  form.value =
    target === 'new'
      ? { name: '', host: '', port: 22, username: 'root', auth_type: 'key', ssh_key_id: '', password: '', description: '' }
      : {
          name: target.name,
          host: target.host,
          port: target.port,
          username: target.username,
          auth_type: target.auth_type,
          ssh_key_id: target.ssh_key_id ?? '',
          password: '',
          description: target.description ?? '',
        }
  editing.value = target
  await loadKeys()
}

const hostChanged = computed(
  () => editing.value && editing.value !== 'new' && (editing.value.host !== form.value.host || editing.value.port !== Number(form.value.port)),
)
const noKeys = computed(() => form.value.auth_type === 'key' && !keys.value.length)

async function save() {
  saving.value = true
  formError.value = ''
  fieldErrors.value = {}
  const f = form.value
  const body: Record<string, unknown> = {
    name: f.name,
    host: f.host,
    port: Number(f.port),
    username: f.username,
    auth_type: f.auth_type,
    description: f.description || null,
  }
  if (f.auth_type === 'key') body.ssh_key_id = f.ssh_key_id === '' ? null : f.ssh_key_id
  // пустой пароль при редактировании = оставить прежний (если способ входа не менялся)
  else if (f.password || editing.value === 'new' || (editing.value as Server).auth_type !== 'password') body.password = f.password
  try {
    const target = editing.value
    if (target === 'new') await api('/servers', { method: 'POST', body })
    else if (target) await api(`/servers/${target.id}`, { method: 'PUT', body })
    editing.value = null
    form.value.password = ''
    await load(target === 'new' ? 1 : meta.value.current_page)
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      fieldErrors.value = e.errors
      formError.value = Object.keys(e.errors).length ? '' : errorMessage(e)
    } else formError.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}

// --- проверка подключения ---
const checking = ref<Server | null>(null)
const checkLoading = ref(false)
const checkResult = ref<CheckResult | null>(null)
const checkError = ref('')

async function runCheck(s: Server) {
  checking.value = s
  checkResult.value = null
  checkError.value = ''
  checkLoading.value = true
  try {
    const res = await api<{ data: CheckResult }>(`/servers/${s.id}/check`, { method: 'POST' })
    checkResult.value = res.data
    checking.value = res.data.server
    replace(res.data.server)
  } catch (e) {
    checkError.value = errorMessage(e, 'Не удалось выполнить проверку')
  } finally {
    checkLoading.value = false
  }
}

// --- сброс отпечатка ---
const resetting = ref<Server | null>(null)
const resetLoading = ref(false)
const resetError = ref('')
async function resetFingerprint() {
  if (!resetting.value) return
  resetLoading.value = true
  resetError.value = ''
  try {
    const res = await api<{ data: Server }>(`/servers/${resetting.value.id}/reset-fingerprint`, { method: 'POST' })
    replace(res.data)
    if (checking.value?.id === res.data.id) checking.value = res.data
    resetting.value = null
  } catch (e) {
    resetError.value = errorMessage(e)
  } finally {
    resetLoading.value = false
  }
}

// --- удаление ---
const removing = ref<Server | null>(null)
const removeLoading = ref(false)
const removeError = ref('')
async function remove() {
  if (!removing.value) return
  removeLoading.value = true
  removeError.value = ''
  try {
    await api(`/servers/${removing.value.id}`, { method: 'DELETE' })
    removing.value = null
    await load()
  } catch (e) {
    removeError.value = errorMessage(e)
  } finally {
    removeLoading.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <div class="flex items-start justify-between gap-4">
      <header class="space-y-0.5">
        <h2 class="text-xl font-semibold tracking-tight">Серверы</h2>
        <p class="text-sm text-muted-foreground">Серверы, которые SystemKeeper бекапит и мониторит по SSH.</p>
      </header>
      <button :class="btnClass.default" @click="openForm('new')"><Icon name="plus" />Добавить сервер</button>
    </div>

    <div v-if="loadError" role="alert" class="flex items-center gap-3 rounded-lg border bg-card px-4 py-3 text-sm text-destructive">
      {{ loadError }}
      <button :class="[btnClass.outlineSm, 'ml-auto']" @click="load()">Повторить</button>
    </div>
    <div v-else-if="loading && !items.length" class="rounded-xl border p-10 text-center text-sm text-muted-foreground">Загрузка…</div>
    <div v-else-if="!items.length" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
      Серверов пока нет. Добавьте первый — и проверьте подключение.
    </div>
    <template v-else>
      <div class="grid gap-4 lg:grid-cols-2" :class="loading && 'opacity-60'">
        <div v-for="s in items" :key="s.id" class="flex min-w-0 flex-col gap-3 rounded-xl border bg-card p-4">
          <div class="flex items-center gap-2">
            <Icon name="server" class="text-muted-foreground" />
            <span class="truncate font-mono font-medium">{{ s.name }}</span>
            <span class="text-xs text-muted-foreground uppercase">{{ s.auth_type === 'key' ? 'ключ' : 'пароль' }}</span>
            <span class="ml-auto flex shrink-0">
              <button :class="btnClass.ghostSm" title="Редактировать" @click="openForm(s)"><Icon name="pencil" /></button>
              <button :class="btnClass.ghostSm" title="Удалить" @click="removeError = ''; removing = s"><Icon name="trash" /></button>
            </span>
          </div>
          <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            <dt class="text-muted-foreground">Адрес</dt>
            <dd class="truncate font-mono">{{ s.username }}@{{ s.host }}:{{ s.port }}</dd>
            <dt class="text-muted-foreground">Вход</dt>
            <dd class="truncate">{{ s.auth_type === 'key' ? (s.ssh_key ? `${s.ssh_key.name} (${s.ssh_key.type})` : 'ключ не выбран') : 'пароль' }}</dd>
            <dt class="text-muted-foreground">Отпечаток</dt>
            <dd class="flex min-w-0 items-center gap-2">
              <span class="truncate font-mono text-xs" :title="s.host_fingerprint ?? ''">{{ s.host_fingerprint ?? 'запомнится при первом подключении' }}</span>
              <button v-if="s.host_fingerprint" class="shrink-0 text-xs text-link hover:underline" @click="resetError = ''; resetting = s">сбросить</button>
            </dd>
            <template v-if="s.description">
              <dt class="text-muted-foreground">Описание</dt>
              <dd class="truncate">{{ s.description }}</dd>
            </template>
          </dl>
          <div class="flex items-center gap-2 text-sm">
            <StatusBadge :tone="status(s).tone" :label="status(s).label" />
            <span v-if="s.last_check_error" class="truncate text-xs text-muted-foreground" :title="s.last_check_error">{{ s.last_check_error }}</span>
            <span class="ml-auto shrink-0 text-xs text-muted-foreground">{{ formatDate(s.last_checked_at) }}</span>
          </div>
          <button :class="[btnClass.outlineSm, 'self-start']" @click="runCheck(s)"><Icon name="plug" />Проверить подключение</button>
        </div>
      </div>
      <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="load" />
    </template>

    <Modal v-if="editing" wide :title="editing === 'new' ? 'Новый сервер' : 'Редактировать сервер'" @close="editing = null">
      <form class="grid gap-4" @submit.prevent="save">
        <div v-if="formError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ formError }}</div>
        <div class="grid gap-2">
          <label for="s-name" class="text-sm leading-none font-medium">Название</label>
          <input id="s-name" v-model="form.name" required maxlength="255" :class="inputClass" :aria-invalid="!!fieldErrors.name" />
          <p v-for="m in fieldErrors.name" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid grid-cols-[1fr_2fr_6rem] gap-4">
          <div class="grid content-start gap-2">
            <label for="s-user" class="text-sm leading-none font-medium">Пользователь</label>
            <input id="s-user" v-model="form.username" required class="font-mono" :class="inputClass" :aria-invalid="!!fieldErrors.username" />
            <p v-for="m in fieldErrors.username" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div class="grid content-start gap-2">
            <label for="s-host" class="text-sm leading-none font-medium">Хост</label>
            <input id="s-host" v-model="form.host" required placeholder="203.0.113.10" class="font-mono" :class="inputClass" :aria-invalid="!!fieldErrors.host" />
            <p v-for="m in fieldErrors.host" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div class="grid content-start gap-2">
            <label for="s-port" class="text-sm leading-none font-medium">Порт</label>
            <input id="s-port" v-model.number="form.port" type="number" min="1" max="65535" required class="font-mono" :class="inputClass" :aria-invalid="!!fieldErrors.port" />
            <p v-for="m in fieldErrors.port" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
        </div>
        <p v-if="hostChanged" class="text-xs text-muted-foreground">При смене хоста или порта сохранённый отпечаток хоста будет сброшен.</p>
        <div class="grid gap-2">
          <span class="text-sm leading-none font-medium">Способ входа</span>
          <div class="flex gap-2">
            <button type="button" :class="form.auth_type === 'key' ? btnClass.default : btnClass.outline" @click="form.auth_type = 'key'">SSH-ключ</button>
            <button type="button" :class="form.auth_type === 'password' ? btnClass.default : btnClass.outline" @click="form.auth_type = 'password'">Пароль</button>
          </div>
          <p v-for="m in fieldErrors.auth_type" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div v-if="form.auth_type === 'key'" class="grid gap-2">
          <label for="s-key" class="text-sm leading-none font-medium">SSH-ключ</label>
          <select id="s-key" v-model="form.ssh_key_id" required :class="inputClass" :aria-invalid="!!fieldErrors.ssh_key_id">
            <option value="" disabled>Выберите ключ</option>
            <option v-for="k in keys" :key="k.id" :value="k.id">{{ k.name }} ({{ k.type }})</option>
          </select>
          <p v-if="noKeys" class="text-xs text-muted-foreground">
            Ключей нет — <RouterLink to="/ssh-keys" class="text-link hover:underline">создайте ключ</RouterLink> в разделе «SSH-ключи».
          </p>
          <p v-for="m in fieldErrors.ssh_key_id" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div v-else class="grid gap-2">
          <label for="s-pass" class="text-sm leading-none font-medium">Пароль</label>
          <input
            id="s-pass"
            v-model="form.password"
            type="password"
            autocomplete="new-password"
            :required="editing === 'new' || editing.auth_type !== 'password'"
            :placeholder="editing !== 'new' && editing.has_password ? 'Оставьте пустым, чтобы не менять' : ''"
            :class="inputClass"
            :aria-invalid="!!fieldErrors.password"
          />
          <p v-for="m in fieldErrors.password" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid gap-2">
          <label for="s-desc" class="text-sm leading-none font-medium">Описание</label>
          <textarea id="s-desc" v-model="form.description" rows="2" :class="textareaClass" class="!min-h-16" />
          <p v-for="m in fieldErrors.description" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" :class="btnClass.outline" @click="editing = null">Отмена</button>
          <button type="submit" :class="btnClass.default" :disabled="saving">
            <Icon v-if="saving" name="loader" class="animate-spin" />Сохранить
          </button>
        </div>
      </form>
    </Modal>

    <Modal v-if="checking" wide :title="`Проверка: ${checking.name}`" :description="`${checking.username}@${checking.host}:${checking.port}`" @close="checkLoading || (checking = null)">
      <div v-if="checkLoading" class="flex items-center gap-2 rounded-lg border px-4 py-3 text-sm text-muted-foreground">
        <Icon name="loader" class="animate-spin" />Подключаемся и выполняем <code class="rounded bg-muted px-1 py-0.5 text-[13px]">uname -a &amp;&amp; uptime</code>…
      </div>
      <div v-else-if="checkError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ checkError }}</div>
      <template v-else-if="checkResult">
        <div class="flex items-center gap-2">
          <StatusBadge :tone="checkResult.status === 'ok' ? 'success' : 'danger'" :label="checkResult.status === 'ok' ? 'Подключение успешно' : 'Не удалось подключиться'" />
          <span class="ml-auto text-xs text-muted-foreground">{{ formatDate(checkResult.checked_at) }}</span>
        </div>
        <div v-if="checkResult.error" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">
          {{ checkResult.error }}
          <div v-if="/отпечат/i.test(checkResult.error) && checking.host_fingerprint" class="mt-2">
            <button type="button" :class="btnClass.outlineSm" @click="resetError = ''; resetting = checking">Принять новый ключ хоста (сбросить отпечаток)</button>
          </div>
        </div>
        <template v-if="checkResult.output">
          <p class="text-xs text-muted-foreground">Код выхода: <span class="font-mono">{{ checkResult.output.exit_code }}</span></p>
          <div v-if="checkResult.output.stdout" class="grid gap-1">
            <span class="text-xs text-muted-foreground">stdout</span>
            <pre class="max-h-64 overflow-auto rounded-md bg-muted/40 px-2 py-1.5 text-xs break-all whitespace-pre-wrap">{{ checkResult.output.stdout }}</pre>
          </div>
          <div v-if="checkResult.output.stderr" class="grid gap-1">
            <span class="text-xs text-muted-foreground">stderr</span>
            <pre class="max-h-64 overflow-auto rounded-md bg-muted/40 px-2 py-1.5 text-xs break-all whitespace-pre-wrap">{{ checkResult.output.stderr }}</pre>
          </div>
        </template>
      </template>
      <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <button type="button" :class="btnClass.outline" :disabled="checkLoading" @click="checking = null">Закрыть</button>
        <button type="button" :class="btnClass.default" :disabled="checkLoading" @click="runCheck(checking)">
          <Icon name="refresh" :class="checkLoading && 'animate-spin'" />Повторить
        </button>
      </div>
    </Modal>

    <ConfirmDialog
      v-if="resetting"
      title="Сбросить отпечаток хоста?"
      :text="`Новый ключ хоста «${resetting.name}» будет принят без проверки при следующем подключении. Делайте это, только если вы сами переустанавливали сервер или ключи SSH.`"
      confirm-label="Сбросить"
      :loading="resetLoading"
      :error="resetError"
      @confirm="resetFingerprint"
      @close="resetting = null"
    />
    <ConfirmDialog
      v-if="removing"
      title="Удалить сервер?"
      :text="`Сервер «${removing.name}» будет удалён без возможности восстановления.`"
      :loading="removeLoading"
      :error="removeError"
      @confirm="remove"
      @close="removing = null"
    />
  </div>
</template>
