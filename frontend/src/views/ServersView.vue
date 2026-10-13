<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import Icon from '@/components/Icon.vue'
import BackupSettingsModal from '@/components/BackupSettingsModal.vue'
import Modal from '@/components/Modal.vue'
import { describeCron } from '@/lib/cron'
import { btnClass, formatDate, inputClass } from '@/lib/ui'

type AuthType = 'key' | 'password'
/** keep — оставить сохранённый ключ, generate — сгенерировать пару в сервисе, paste — вставить свой. */
type KeySource = 'keep' | 'generate' | 'paste'

interface Server {
  id: number
  name: string
  description: string | null
  host: string
  port: number
  username: string
  auth_type: AuthType
  has_private_key: boolean
  has_passphrase: boolean
  has_password: boolean
  public_key: string | null
  backup?: { enabled: boolean; schedule: string | null; paths_count: number; next_run_at: string | null }
  host_fingerprint: string | null
  last_check: { status: 'ok' | 'failed' | null; error: string | null; checked_at: string | null }
}

interface Page {
  data: Server[]
  meta: { current_page: number; last_page: number; total: number }
}

const servers = ref<Server[]>([])
const meta = ref<Page['meta'] | null>(null)
const page = ref(1)
const search = ref('')
const loading = ref(false)
const loadError = ref('')

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const qs = new URLSearchParams({ page: String(page.value) })
    if (search.value.trim()) qs.set('search', search.value.trim())
    const res = await api<Page>(`/servers?${qs}`)
    servers.value = res.data
    meta.value = res.meta
  } catch {
    loadError.value = 'Не удалось загрузить серверы'
  } finally {
    loading.value = false
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    load()
  }, 300)
})

function goTo(p: number) {
  page.value = p
  load()
}

function replace(server: Server) {
  servers.value = servers.value.map((s) => (s.id === server.id ? server : s))
}

// Создание и редактирование
const editing = ref<Server | null>(null)
const formOpen = ref(false)
const form = reactive({
  name: '',
  description: '',
  host: '',
  port: 22,
  username: 'root',
  auth_type: 'key' as AuthType,
  key_source: 'generate' as KeySource,
  key_type: 'ed25519' as 'ed25519' | 'rsa',
  private_key: '',
  passphrase: '',
  password: '',
})
const formErrors = ref<Record<string, string[]>>({})
const formError = ref('')
const saving = ref(false)

/** При редактировании без смены способа авторизации учётные данные можно не вводить — останутся прежние. */
const keepsCredential = computed(() => !!editing.value && editing.value.auth_type === form.auth_type)

watch(keepsCredential, (keeps) => {
  if (!keeps && form.key_source === 'keep') form.key_source = 'generate'
})

// Открытый ключ для authorized_keys — показывается после генерации и по кнопке
const publicKeyFor = ref<Server | null>(null)
const copied = ref(false)

async function copyPublicKey() {
  if (!publicKeyFor.value?.public_key) return
  await navigator.clipboard.writeText(publicKeyFor.value.public_key)
  copied.value = true
  setTimeout(() => (copied.value = false), 1500)
}

function installCommand(key: string) {
  return `mkdir -p ~/.ssh && chmod 700 ~/.ssh && echo '${key}' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys`
}

function openForm(server: Server | null) {
  editing.value = server
  Object.assign(form, {
    name: server?.name ?? '',
    description: server?.description ?? '',
    host: server?.host ?? '',
    port: server?.port ?? 22,
    username: server?.username ?? 'root',
    auth_type: server?.auth_type ?? 'key',
    key_source: server?.auth_type === 'key' ? 'keep' : 'generate',
    key_type: 'ed25519',
    private_key: '',
    passphrase: '',
    password: '',
  })
  formErrors.value = {}
  formError.value = ''
  formOpen.value = true
}

async function save() {
  saving.value = true
  formErrors.value = {}
  formError.value = ''
  const body: Record<string, unknown> = {
    name: form.name,
    description: form.description || null,
    host: form.host.trim(),
    port: form.port,
    username: form.username,
    auth_type: form.auth_type,
  }
  if (form.auth_type === 'key' && form.key_source === 'generate') {
    body.generate_key = true
    body.key_type = form.key_type
  }
  if (form.auth_type === 'key' && form.key_source === 'paste') {
    body.private_key = form.private_key.trim() + '\n'
    body.passphrase = form.passphrase || null
  }
  if (form.auth_type === 'password' && form.password) body.password = form.password
  try {
    const res = editing.value
      ? await api<{ data: Server }>(`/servers/${editing.value.id}`, { method: 'PATCH', body })
      : await api<{ data: Server }>('/servers', { method: 'POST', body })
    formOpen.value = false
    await load()
    if (body.generate_key) {
      // Новый ключ ещё не установлен на сервере — показываем открытый ключ, проверять рано
      copied.value = false
      publicKeyFor.value = res.data
    } else if (!editing.value) {
      // Новый сервер сразу проверяем — чтобы было видно, работает ли подключение
      check(res.data)
    }
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) formErrors.value = e.errors
    else formError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

// Проверка подключения
const checking = ref(new Set<number>())
const checkOutput = ref<{ server: Server; output: string | null; error: string | null } | null>(null)

async function check(server: Server, showResult = false) {
  checking.value.add(server.id)
  try {
    const res = await api<{ data: { status: string; error: string | null; output: string | null; server: Server } }>(
      `/servers/${server.id}/check`,
      { method: 'POST' },
    )
    replace(res.data.server)
    if (showResult) checkOutput.value = { server: res.data.server, output: res.data.output, error: res.data.error }
  } catch (e) {
    if (showResult) checkOutput.value = { server, output: null, error: e instanceof ApiError ? e.message : 'Не удалось выполнить проверку' }
  } finally {
    checking.value.delete(server.id)
  }
}

async function resetFingerprint(server: Server) {
  const res = await api<{ data: Server }>(`/servers/${server.id}/reset-fingerprint`, { method: 'POST' })
  replace(res.data)
  if (checkOutput.value?.server.id === server.id) checkOutput.value.server = res.data
}

// Удаление
// Настройки бекапа
const backupFor = ref<Server | null>(null)

async function onBackupSaved() {
  backupFor.value = null
  await load()
}

const deleting = ref<Server | null>(null)
const deleteError = ref('')

async function confirmDelete() {
  if (!deleting.value) return
  deleteError.value = ''
  try {
    await api(`/servers/${deleting.value.id}`, { method: 'DELETE' })
    deleting.value = null
    if (servers.value.length === 1 && page.value > 1) page.value--
    await load()
  } catch (e) {
    deleteError.value = e instanceof ApiError ? e.message : 'Не удалось удалить'
  }
}

onMounted(load)

const label = 'text-sm leading-none font-medium'
const iconBtn = 'inline-flex size-8 items-center justify-center rounded-md hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-40'
const segment = 'flex h-9 flex-1 cursor-pointer items-center justify-center rounded-md border text-sm has-checked:border-primary has-checked:bg-accent has-checked:text-accent-foreground'
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div class="space-y-0.5">
        <h2 class="text-xl font-semibold tracking-tight">Сервера</h2>
        <p class="text-sm text-muted-foreground">Серверы и SSH-подключение к ним</p>
      </div>
      <button :class="btnClass.primary" @click="openForm(null)"><Icon name="plus" />Добавить</button>
    </header>

    <input v-model="search" type="search" placeholder="Поиск по названию, хосту или описанию" :class="inputClass" class="max-w-sm" />

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>

    <div class="overflow-x-auto rounded-xl border bg-card">
      <table class="w-full text-sm">
        <thead class="border-b text-left text-muted-foreground">
          <tr>
            <th class="px-4 py-3 font-medium">Название</th>
            <th class="px-4 py-3 font-medium">Подключение</th>
            <th class="px-4 py-3 font-medium">SSH</th>
            <th class="px-4 py-3 font-medium">Бекап</th>
            <th class="w-48 px-4 py-3" />
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="s in servers" :key="s.id">
            <td class="px-4 py-3">
              <div class="font-medium">{{ s.name }}</div>
              <div v-if="s.description" class="max-w-xs truncate text-xs text-muted-foreground">{{ s.description }}</div>
            </td>
            <td class="px-4 py-3 whitespace-nowrap">
              <div class="font-mono text-xs">{{ s.username }}@{{ s.host }}{{ s.port === 22 ? '' : `:${s.port}` }}</div>
              <div class="text-xs text-muted-foreground">{{ s.auth_type === 'key' ? 'по ключу' : 'по паролю' }}</div>
            </td>
            <td class="px-4 py-3">
              <span v-if="checking.has(s.id)" class="inline-flex items-center gap-1.5 text-muted-foreground"><Icon name="loader" class="size-4 animate-spin" />Проверка…</span>
              <span v-else-if="s.last_check.status === 'ok'" class="inline-flex items-center gap-1.5" :title="`Проверено ${formatDate(s.last_check.checked_at)}`">
                <span class="size-2 rounded-full bg-emerald-500" />Работает
              </span>
              <span v-else-if="s.last_check.status === 'failed'" class="inline-flex max-w-56 items-center gap-1.5 text-destructive" :title="s.last_check.error ?? ''">
                <span class="size-2 shrink-0 rounded-full bg-destructive" /><span class="truncate">{{ s.last_check.error }}</span>
              </span>
              <span v-else class="text-muted-foreground">Не проверялся</span>
            </td>
            <td class="px-4 py-3">
              <button type="button" class="text-left hover:underline" @click="backupFor = s">
                <template v-if="s.backup?.enabled && s.backup.schedule">
                  <div class="whitespace-nowrap">{{ describeCron(s.backup.schedule) }}</div>
                  <div class="text-xs text-muted-foreground">
                    {{ s.backup.paths_count }} {{ s.backup.paths_count === 1 ? 'каталог' : s.backup.paths_count < 5 ? 'каталога' : 'каталогов' }}<template v-if="s.backup.next_run_at"> · след. {{ formatDate(s.backup.next_run_at) }}</template>
                  </div>
                </template>
                <span v-else class="text-muted-foreground">Не настроен</span>
              </button>
            </td>
            <td class="px-4 py-2">
              <div class="flex justify-end gap-1">
                <button :class="iconBtn" title="Настройки бекапа" @click="backupFor = s"><Icon name="archive" /></button>
                <button v-if="s.public_key" :class="iconBtn" title="Открытый ключ" @click="(publicKeyFor = s), (copied = false)"><Icon name="key" /></button>
                <button :class="iconBtn" title="Проверить подключение" :disabled="checking.has(s.id)" @click="check(s, true)"><Icon name="plug" /></button>
                <button :class="iconBtn" title="Изменить" @click="openForm(s)"><Icon name="pencil" /></button>
                <button :class="iconBtn" class="text-destructive" title="Удалить" @click="(deleting = s), (deleteError = '')"><Icon name="trash" /></button>
              </div>
            </td>
          </tr>
          <tr v-if="!servers.length && !loading">
            <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">{{ search ? 'Ничего не нашли' : 'Серверов пока нет' }}</td>
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

    <Modal v-if="formOpen" wide :title="editing ? 'Изменить сервер' : 'Новый сервер'" @close="formOpen = false">
      <form class="grid gap-4" @submit.prevent="save">
        <div class="grid gap-2">
          <label for="s-name" :class="label">Название</label>
          <input id="s-name" v-model="form.name" required placeholder="web-1" :class="inputClass" :aria-invalid="!!formErrors.name" />
          <p v-for="m in formErrors.name" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid gap-2">
          <label for="s-description" :class="label">Описание</label>
          <input id="s-description" v-model="form.description" placeholder="Необязательно" :class="inputClass" :aria-invalid="!!formErrors.description" />
          <p v-for="m in formErrors.description" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid grid-cols-[1fr_6rem] gap-4">
          <div class="grid gap-2">
            <label for="s-host" :class="label">Хост</label>
            <input id="s-host" v-model="form.host" required placeholder="192.168.1.10 или example.com" :class="inputClass" :aria-invalid="!!formErrors.host" />
          </div>
          <div class="grid gap-2">
            <label for="s-port" :class="label">Порт</label>
            <input id="s-port" v-model.number="form.port" type="number" min="1" max="65535" required :class="inputClass" :aria-invalid="!!formErrors.port" />
          </div>
        </div>
        <p v-for="m in [...(formErrors.host ?? []), ...(formErrors.port ?? [])]" :key="m" class="-mt-2 text-sm text-destructive">{{ m }}</p>
        <div class="grid gap-2">
          <label for="s-username" :class="label">Пользователь</label>
          <input id="s-username" v-model="form.username" required autocomplete="off" :class="inputClass" :aria-invalid="!!formErrors.username" />
          <p v-for="m in formErrors.username" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>

        <div class="grid gap-2">
          <span :class="label">Авторизация</span>
          <div class="flex gap-2">
            <label :class="segment"><input v-model="form.auth_type" type="radio" value="key" class="sr-only" />По ключу</label>
            <label :class="segment"><input v-model="form.auth_type" type="radio" value="password" class="sr-only" />По паролю</label>
          </div>
        </div>

        <template v-if="form.auth_type === 'key'">
          <div class="grid gap-2">
            <span :class="label">Ключ</span>
            <div class="flex gap-2">
              <label v-if="keepsCredential" :class="segment"><input v-model="form.key_source" type="radio" value="keep" class="sr-only" />Текущий</label>
              <label :class="segment"><input v-model="form.key_source" type="radio" value="generate" class="sr-only" />{{ keepsCredential ? 'Новый' : 'Сгенерировать' }}</label>
              <label :class="segment"><input v-model="form.key_source" type="radio" value="paste" class="sr-only" />Вставить свой</label>
            </div>
          </div>
          <div v-if="form.key_source === 'generate'" class="grid gap-2">
            <span :class="label">Тип ключа</span>
            <div class="flex gap-2">
              <label :class="segment"><input v-model="form.key_type" type="radio" value="ed25519" class="sr-only" />Ed25519</label>
              <label :class="segment"><input v-model="form.key_type" type="radio" value="rsa" class="sr-only" />RSA 4096</label>
            </div>
            <p class="text-sm text-muted-foreground">
              Пара ключей создаётся в SystemKeeper, закрытый ключ хранится зашифрованным и никуда не выдаётся. После сохранения покажем открытый ключ — его нужно добавить в <code class="font-mono text-xs">~/.ssh/authorized_keys</code> на сервере.
              <template v-if="keepsCredential"> Текущий ключ будет заменён.</template>
            </p>
          </div>
          <div v-if="form.key_source === 'keep' && editing?.public_key" class="grid gap-2">
            <button type="button" :class="btnClass.outline" class="justify-self-start" @click="(publicKeyFor = editing), (copied = false)">Показать открытый ключ</button>
          </div>
          <template v-if="form.key_source === 'paste'">
          <div class="grid gap-2">
            <label for="s-key" :class="label">Закрытый ключ</label>
            <textarea
              id="s-key"
              v-model="form.private_key"
              required
              rows="6"
              spellcheck="false"
              placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
              :class="inputClass"
              class="h-auto py-2 font-mono text-xs"
              :aria-invalid="!!formErrors.private_key"
            />
            <p v-for="m in formErrors.private_key" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div class="grid gap-2">
            <label for="s-passphrase" :class="label">Парольная фраза ключа</label>
            <input id="s-passphrase" v-model="form.passphrase" type="password" autocomplete="new-password" placeholder="Если ключ зашифрован" :class="inputClass" :aria-invalid="!!formErrors.passphrase" />
            <p v-for="m in formErrors.passphrase" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          </template>
        </template>
        <div v-else class="grid gap-2">
          <label for="s-password" :class="label">Пароль</label>
          <input
            id="s-password"
            v-model="form.password"
            type="password"
            :required="!keepsCredential"
            autocomplete="new-password"
            :placeholder="keepsCredential ? 'Пароль сохранён. Введите новый, чтобы заменить' : ''"
            :class="inputClass"
            :aria-invalid="!!formErrors.password"
          />
          <p v-for="m in formErrors.password" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>

        <p v-if="formError" class="text-sm text-destructive">{{ formError }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" :class="btnClass.outline" @click="formOpen = false">Отмена</button>
          <button type="submit" :disabled="saving" :class="btnClass.primary">{{ editing ? 'Сохранить' : 'Добавить' }}</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="checkOutput" wide :title="`Проверка: ${checkOutput.server.name}`" @close="checkOutput = null">
      <div class="grid gap-4 text-sm">
        <p v-if="!checkOutput.error" class="flex items-center gap-2"><span class="size-2 rounded-full bg-emerald-500" />Подключение работает</p>
        <p v-else class="text-destructive">{{ checkOutput.error }}</p>
        <pre v-if="checkOutput.output" class="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs whitespace-pre-wrap">{{ checkOutput.output }}</pre>
        <div v-if="checkOutput.server.host_fingerprint" class="grid gap-1">
          <span class="text-muted-foreground">Отпечаток хоста</span>
          <code class="font-mono text-xs break-all">{{ checkOutput.server.host_fingerprint }}</code>
        </div>
        <p v-if="checkOutput.error === 'Отпечаток хоста изменился'" class="text-muted-foreground">
          Ключ сервера не совпал с запомненным. Если сервер переустанавливали, сбросьте отпечаток и проверьте снова; иначе это может быть подмена.
        </p>
        <div class="flex justify-end gap-2">
          <button v-if="checkOutput.server.host_fingerprint" :class="btnClass.outline" @click="resetFingerprint(checkOutput.server)">Сбросить отпечаток</button>
          <button :class="btnClass.primary" :disabled="checking.has(checkOutput.server.id)" @click="check(checkOutput.server, true)">Проверить снова</button>
        </div>
      </div>
    </Modal>

    <Modal v-if="publicKeyFor" wide :title="`Открытый ключ: ${publicKeyFor.name}`" @close="publicKeyFor = null">
      <div class="grid gap-4 text-sm">
        <p class="text-muted-foreground">
          Добавьте ключ в <code class="font-mono text-xs">~/.ssh/authorized_keys</code> пользователя <b class="text-foreground">{{ publicKeyFor.username }}</b> на {{ publicKeyFor.host }}, затем проверьте подключение.
        </p>
        <pre class="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs break-all whitespace-pre-wrap">{{ publicKeyFor.public_key }}</pre>
        <div class="grid gap-1">
          <span class="text-muted-foreground">Или выполните на сервере:</span>
          <pre class="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs break-all whitespace-pre-wrap">{{ installCommand(publicKeyFor.public_key ?? '') }}</pre>
        </div>
        <div class="flex justify-end gap-2">
          <button :class="btnClass.outline" @click="copyPublicKey">{{ copied ? 'Скопировано' : 'Скопировать ключ' }}</button>
          <button :class="btnClass.primary" @click="check(publicKeyFor, true), (publicKeyFor = null)">Проверить подключение</button>
        </div>
      </div>
    </Modal>

    <BackupSettingsModal v-if="backupFor" :server="backupFor" @close="backupFor = null" @saved="onBackupSaved" />

    <Modal v-if="deleting" title="Удалить сервер?" @close="deleting = null">
      <p class="text-sm text-muted-foreground">{{ deleting.name }} ({{ deleting.host }}) и его учётные данные SSH будут удалены.</p>
      <p v-if="deleteError" class="mt-3 text-sm text-destructive">{{ deleteError }}</p>
      <div class="mt-6 flex justify-end gap-2">
        <button :class="btnClass.outline" @click="deleting = null">Отмена</button>
        <button :class="btnClass.destructive" @click="confirmDelete">Удалить</button>
      </div>
    </Modal>
  </div>
</template>
