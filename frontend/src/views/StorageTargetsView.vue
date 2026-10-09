<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { Paginated, StorageCheck, StorageTarget } from '@/api/types'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import Pagination from '@/components/Pagination.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { btnClass, errorMessage, formatBytes, formatDate, inputClass } from '@/lib/format'

const items = ref<StorageTarget[]>([])
const meta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
const loading = ref(true)
const loadError = ref('')

async function load(page = meta.value.current_page) {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api<Paginated<StorageTarget>>(`/storage-targets?page=${page}&per_page=15`)
    if (!res.data.length && page > res.meta.last_page && res.meta.last_page > 0) return load(res.meta.last_page)
    items.value = res.data
    meta.value = res.meta
  } catch (e) {
    loadError.value = errorMessage(e, 'Не удалось загрузить список хранилищ')
  } finally {
    loading.value = false
  }
}
onMounted(() => load(1))

const replace = (t: StorageTarget) => {
  const i = items.value.findIndex((x) => x.id === t.id)
  if (i >= 0) items.value[i] = t
}
const typeLabel = (t: StorageTarget) => (t.type === 'local' ? 'Локальный каталог' : 'Яндекс.Диск')
const status = (t: StorageTarget) =>
  t.last_check_status === 'ok'
    ? ({ tone: 'success', label: 'Норма' } as const)
    : t.last_check_status === 'failed'
      ? ({ tone: 'danger', label: 'Ошибка' } as const)
      : ({ tone: 'neutral', label: 'Не проверялось' } as const)

// --- форма ---
const editing = ref<'new' | StorageTarget | null>(null)
const form = ref({ name: '', type: 'local', base_path: '', token: '', enabled: true })
const saving = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

function openForm(t: 'new' | StorageTarget) {
  fieldErrors.value = {}
  formError.value = ''
  form.value =
    t === 'new'
      ? { name: '', type: 'local', base_path: '/', token: '', enabled: true }
      : { name: t.name, type: t.type, base_path: t.base_path, token: '', enabled: t.enabled }
  editing.value = t
}

async function save() {
  saving.value = true
  formError.value = ''
  fieldErrors.value = {}
  const f = form.value
  const body: Record<string, unknown> = { name: f.name, base_path: f.base_path, enabled: f.enabled }
  if (editing.value === 'new') body.type = f.type
  if (f.type === 'yandex_disk' && f.token) body.token = f.token
  try {
    const target = editing.value
    if (target === 'new') await api('/storage-targets', { method: 'POST', body })
    else if (target) await api(`/storage-targets/${target.id}`, { method: 'PUT', body })
    editing.value = null
    form.value.token = ''
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

// --- вкл/выкл ---
const toggleError = ref('')
async function toggle(t: StorageTarget) {
  toggleError.value = ''
  try {
    replace((await api<{ data: StorageTarget }>(`/storage-targets/${t.id}`, { method: 'PATCH', body: { enabled: !t.enabled } })).data)
  } catch (e) {
    toggleError.value = errorMessage(e, 'Не удалось изменить состояние')
  }
}

// --- проверка ---
const checking = ref<StorageTarget | null>(null)
const checkLoading = ref(false)
const checkResult = ref<StorageCheck | null>(null)
const checkError = ref('')
async function runCheck(t: StorageTarget) {
  checking.value = t
  checkResult.value = null
  checkError.value = ''
  checkLoading.value = true
  try {
    checkResult.value = (await api<{ data: StorageCheck }>(`/storage-targets/${t.id}/check`, { method: 'POST' })).data
    // статус сохранён на сервере — обновляем карточку
    replace((await api<{ data: StorageTarget }>(`/storage-targets/${t.id}`)).data)
  } catch (e) {
    checkError.value = errorMessage(e, 'Не удалось выполнить проверку')
  } finally {
    checkLoading.value = false
  }
}
const usedPercent = (c: StorageCheck) => (c.total_space ? Math.min(100, Math.round(((c.used_space ?? 0) / c.total_space) * 100)) : null)
const barTone = (p: number) => (p >= 90 ? 'bg-destructive' : p >= 70 ? 'bg-warning' : 'bg-chart-1')

// --- удаление ---
const removing = ref<StorageTarget | null>(null)
const removeLoading = ref(false)
const removeError = ref('')
async function remove() {
  if (!removing.value) return
  removeLoading.value = true
  removeError.value = ''
  try {
    await api(`/storage-targets/${removing.value.id}`, { method: 'DELETE' })
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
        <h2 class="text-xl font-semibold tracking-tight">Хранилища</h2>
        <p class="text-sm text-muted-foreground">Куда складываются бекапы: локальный каталог или аккаунты Яндекс.Диска.</p>
      </header>
      <button :class="btnClass.default" @click="openForm('new')"><Icon name="plus" />Добавить хранилище</button>
    </div>

    <div v-if="toggleError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ toggleError }}</div>
    <div v-if="loadError" role="alert" class="flex items-center gap-3 rounded-lg border bg-card px-4 py-3 text-sm text-destructive">
      {{ loadError }}
      <button :class="[btnClass.outlineSm, 'ml-auto']" @click="load()">Повторить</button>
    </div>
    <div v-else-if="loading && !items.length" class="rounded-xl border p-10 text-center text-sm text-muted-foreground">Загрузка…</div>
    <div v-else-if="!items.length" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
      Хранилищ пока нет. Добавьте каталог или аккаунт Яндекс.Диска.
    </div>
    <template v-else>
      <div class="grid gap-4 lg:grid-cols-2" :class="loading && 'opacity-60'">
        <div v-for="t in items" :key="t.id" class="flex min-w-0 flex-col gap-3 rounded-xl border bg-card p-4" :class="!t.enabled && 'opacity-70'">
          <div class="flex items-center gap-2">
            <Icon name="archive" class="text-muted-foreground" />
            <span class="truncate font-mono font-medium">{{ t.name }}</span>
            <span class="text-xs text-muted-foreground uppercase">{{ typeLabel(t) }}</span>
            <span class="ml-auto flex shrink-0">
              <button :class="btnClass.ghostSm" title="Редактировать" @click="openForm(t)"><Icon name="pencil" /></button>
              <button :class="btnClass.ghostSm" title="Удалить" @click="removeError = ''; removing = t"><Icon name="trash" /></button>
            </span>
          </div>
          <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            <dt class="text-muted-foreground">Путь</dt>
            <dd class="truncate font-mono">{{ t.base_path }}</dd>
            <template v-if="t.type === 'yandex_disk'">
              <dt class="text-muted-foreground">Токен</dt>
              <dd>{{ t.has_token ? 'задан' : 'не задан' }}</dd>
            </template>
            <dt class="text-muted-foreground">Состояние</dt>
            <dd>{{ t.enabled ? 'включено' : 'выключено' }}</dd>
          </dl>
          <div class="flex items-center gap-2 text-sm">
            <StatusBadge :tone="status(t).tone" :label="status(t).label" />
            <span v-if="t.last_check_error" class="truncate text-xs text-muted-foreground" :title="t.last_check_error">{{ t.last_check_error }}</span>
            <span class="ml-auto shrink-0 text-xs text-muted-foreground">{{ formatDate(t.last_checked_at) }}</span>
          </div>
          <div class="flex gap-2">
            <button :class="btnClass.outlineSm" @click="runCheck(t)"><Icon name="plug" />Проверить</button>
            <button :class="btnClass.outlineSm" @click="toggle(t)">{{ t.enabled ? 'Выключить' : 'Включить' }}</button>
          </div>
        </div>
      </div>
      <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="load" />
    </template>

    <Modal v-if="editing" wide :title="editing === 'new' ? 'Новое хранилище' : 'Редактировать хранилище'" @close="editing = null">
      <form class="grid gap-4" @submit.prevent="save">
        <div v-if="formError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ formError }}</div>
        <div class="grid gap-2">
          <label for="t-name" class="text-sm leading-none font-medium">Название</label>
          <input id="t-name" v-model="form.name" required maxlength="255" :class="inputClass" :aria-invalid="!!fieldErrors.name" />
          <p v-for="m in fieldErrors.name" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div v-if="editing === 'new'" class="grid gap-2">
          <span class="text-sm leading-none font-medium">Тип</span>
          <div class="flex gap-2">
            <button type="button" :class="form.type === 'local' ? btnClass.default : btnClass.outline" @click="form.type = 'local'">Локальный каталог</button>
            <button type="button" :class="form.type === 'yandex_disk' ? btnClass.default : btnClass.outline" @click="form.type = 'yandex_disk'">Яндекс.Диск</button>
          </div>
          <p v-for="m in fieldErrors.type" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid gap-2">
          <label for="t-path" class="text-sm leading-none font-medium">Базовый путь</label>
          <input id="t-path" v-model="form.base_path" required class="font-mono" :placeholder="form.type === 'local' ? '/var/backups' : '/SystemKeeper'" :class="inputClass" :aria-invalid="!!fieldErrors.base_path" />
          <p class="text-xs text-muted-foreground">Абсолютный путь, без «..».</p>
          <p v-for="m in fieldErrors.base_path" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div v-if="form.type === 'yandex_disk'" class="grid gap-2">
          <label for="t-token" class="text-sm leading-none font-medium">OAuth-токен Яндекс.Диска</label>
          <input
            id="t-token"
            v-model="form.token"
            type="password"
            autocomplete="new-password"
            :required="editing === 'new'"
            :placeholder="editing !== 'new' && editing.has_token ? 'Оставьте пустым, чтобы не менять' : ''"
            class="font-mono"
            :class="inputClass"
            :aria-invalid="!!fieldErrors.token"
          />
          <p v-for="m in fieldErrors.token" :key="m" class="text-sm text-destructive">{{ m }}</p>
          <details class="rounded-lg border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
            <summary class="cursor-pointer font-medium text-foreground">Как получить токен</summary>
            <ol class="mt-2 list-decimal space-y-1 pl-4">
              <li>
                На <a href="https://oauth.yandex.ru/client/new" target="_blank" rel="noopener" class="text-link hover:underline">oauth.yandex.ru/client/new</a>
                создайте приложение: платформа «Веб-сервисы», Callback URI — <code class="rounded bg-muted px-1 py-0.5 font-mono text-[13px]">https://oauth.yandex.ru/verification_code</code>,
                доступы — <code class="rounded bg-muted px-1 py-0.5 font-mono text-[13px]">cloud_api:disk.read</code>, <code class="rounded bg-muted px-1 py-0.5 font-mono text-[13px]">cloud_api:disk.write</code>,
                <code class="rounded bg-muted px-1 py-0.5 font-mono text-[13px]">cloud_api:disk.info</code> (для папки приложения — <code class="rounded bg-muted px-1 py-0.5 font-mono text-[13px]">cloud_api:disk.app_folder</code>).
              </li>
              <li>
                Откройте <code class="rounded bg-muted px-1 py-0.5 font-mono text-[13px] break-all">https://oauth.yandex.ru/authorize?response_type=token&amp;client_id=&lt;ID приложения&gt;</code>
                под нужным аккаунтом и разрешите доступ; токен будет в адресе страницы после <code class="rounded bg-muted px-1 py-0.5 font-mono text-[13px]">access_token=</code>.
              </li>
              <li>Вставьте его сюда, сохраните и нажмите «Проверить». Для каждого аккаунта — отдельное хранилище.</li>
            </ol>
          </details>
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

    <Modal v-if="checking" wide :title="`Проверка: ${checking.name}`" :description="`${typeLabel(checking)} · ${checking.base_path}`" @close="checkLoading || (checking = null)">
      <div v-if="checkLoading" class="flex items-center gap-2 rounded-lg border px-4 py-3 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Проверяем…</div>
      <div v-else-if="checkError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ checkError }}</div>
      <template v-else-if="checkResult">
        <div><StatusBadge :tone="checkResult.status === 'ok' ? 'success' : 'danger'" :label="checkResult.status === 'ok' ? 'Хранилище доступно' : 'Проверка не пройдена'" /></div>
        <div v-if="checkResult.error" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ checkResult.error }}</div>
        <div v-if="checkResult.total_space != null" class="grid gap-2">
          <div v-if="usedPercent(checkResult) != null" class="h-1.5 rounded-full bg-muted">
            <div class="h-1.5 rounded-full" :class="barTone(usedPercent(checkResult)!)" :style="{ width: `${usedPercent(checkResult)}%` }" />
          </div>
          <dl class="grid grid-cols-3 gap-4 text-sm tabular-nums">
            <div><dt class="text-xs text-muted-foreground">Всего</dt><dd>{{ formatBytes(checkResult.total_space) }}</dd></div>
            <div><dt class="text-xs text-muted-foreground">Занято</dt><dd>{{ formatBytes(checkResult.used_space) }}<span v-if="usedPercent(checkResult) != null" class="text-muted-foreground"> ({{ usedPercent(checkResult) }}%)</span></dd></div>
            <div><dt class="text-xs text-muted-foreground">Свободно</dt><dd>{{ formatBytes(checkResult.free_space) }}</dd></div>
          </dl>
        </div>
      </template>
      <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <button type="button" :class="btnClass.outline" :disabled="checkLoading" @click="checking = null">Закрыть</button>
        <button type="button" :class="btnClass.default" :disabled="checkLoading" @click="runCheck(checking)"><Icon name="refresh" :class="checkLoading && 'animate-spin'" />Повторить</button>
      </div>
    </Modal>

    <ConfirmDialog
      v-if="removing"
      title="Удалить хранилище?"
      :text="`Хранилище «${removing.name}» и записи о копиях в нём будут удалены. Файлы в самом хранилище останутся.`"
      :loading="removeLoading"
      :error="removeError"
      @confirm="remove"
      @close="removing = null"
    />
  </div>
</template>
