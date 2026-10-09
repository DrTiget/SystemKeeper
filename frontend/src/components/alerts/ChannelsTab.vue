<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { AlertChannel, AlertChannelType, Paginated } from '@/api/types'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import Pagination from '@/components/Pagination.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { CHANNEL_TYPES } from '@/lib/alerts'
import { btnClass, errorMessage, inputClass } from '@/lib/format'

const items = ref<AlertChannel[]>([])
const meta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
const loading = ref(true)
const loadError = ref('')

async function load(page = meta.value.current_page) {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api<Paginated<AlertChannel>>(`/alert-channels?page=${page}&per_page=15`)
    if (!res.data.length && page > res.meta.last_page && res.meta.last_page > 0) return load(res.meta.last_page)
    items.value = res.data
    meta.value = res.meta
  } catch (e) {
    loadError.value = errorMessage(e, 'Не удалось загрузить каналы')
  } finally {
    loading.value = false
  }
}
onMounted(() => load(1))

const summary = (c: AlertChannel) =>
  c.type === 'telegram' ? `chat_id: ${c.settings.chat_id ?? '—'}${c.settings.has_bot_token ? '' : ' · токен не задан'}`
  : c.type === 'email' ? (c.settings.recipients ?? []).join(', ') || 'нет адресов'
  : c.type === 'webpush' ? 'Все подписанные браузеры'
  : 'Скоро: отправка в приложение пока отключена'

// --- форма ---
const editing = ref<'new' | AlertChannel | null>(null)
const form = ref({ name: '', type: 'telegram' as AlertChannelType, enabled: true, bot_token: '', chat_id: '', recipients: '' })
const saving = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const fe = (k: string) => fieldErrors.value[k] ?? fieldErrors.value[`settings.${k}`] ?? []

function openForm(c: 'new' | AlertChannel) {
  fieldErrors.value = {}
  formError.value = ''
  form.value =
    c === 'new'
      ? { name: '', type: 'telegram', enabled: true, bot_token: '', chat_id: '', recipients: '' }
      : { name: c.name, type: c.type, enabled: c.enabled, bot_token: '', chat_id: c.settings.chat_id ?? '', recipients: (c.settings.recipients ?? []).join('\n') }
  editing.value = c
}

async function save() {
  saving.value = true
  formError.value = ''
  fieldErrors.value = {}
  const f = form.value
  const settings: Record<string, unknown> = {}
  if (f.type === 'telegram') {
    settings.chat_id = f.chat_id
    if (f.bot_token) settings.bot_token = f.bot_token
  } else if (f.type === 'email') settings.recipients = f.recipients.split(/[\s,;]+/).filter(Boolean)
  const body: Record<string, unknown> = { name: f.name, enabled: f.enabled, settings }
  if (editing.value === 'new') body.type = f.type
  try {
    const t = editing.value
    if (t === 'new') await api('/alert-channels', { method: 'POST', body })
    else if (t) await api(`/alert-channels/${t.id}`, { method: 'PUT', body })
    editing.value = null
    form.value.bot_token = ''
    await load(t === 'new' ? 1 : meta.value.current_page)
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      fieldErrors.value = e.errors
      formError.value = Object.keys(e.errors).length ? '' : errorMessage(e)
    } else formError.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}

// --- тест ---
const testing = ref<AlertChannel | null>(null)
const testLoading = ref(false)
const testResult = ref<{ ok: boolean; skipped: boolean; error: string | null } | null>(null)
const testError = ref('')
async function runTest(c: AlertChannel) {
  testing.value = c
  testResult.value = null
  testError.value = ''
  testLoading.value = true
  try {
    testResult.value = (await api<{ data: { ok: boolean; skipped: boolean; error: string | null } }>(`/alert-channels/${c.id}/test`, { method: 'POST' })).data
  } catch (e) {
    testError.value = errorMessage(e, 'Не удалось отправить тест')
  } finally {
    testLoading.value = false
  }
}

// --- удаление ---
const removing = ref<AlertChannel | null>(null)
const removeLoading = ref(false)
const removeError = ref('')
async function remove() {
  if (!removing.value) return
  removeLoading.value = true
  removeError.value = ''
  try {
    await api(`/alert-channels/${removing.value.id}`, { method: 'DELETE' })
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
  <div class="flex flex-col gap-4">
    <div class="flex justify-end"><button :class="btnClass.default" @click="openForm('new')"><Icon name="plus" />Добавить канал</button></div>
    <div v-if="loadError" role="alert" class="flex items-center gap-3 rounded-lg border bg-card px-4 py-3 text-sm text-destructive">
      {{ loadError }}<button :class="[btnClass.outlineSm, 'ml-auto']" @click="load()">Повторить</button>
    </div>
    <div v-else-if="loading && !items.length" class="rounded-xl border p-10 text-center text-sm text-muted-foreground">Загрузка…</div>
    <div v-else-if="!items.length" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">Каналов пока нет. Добавьте Telegram, Email или Web Push.</div>
    <template v-else>
      <div class="grid gap-4 lg:grid-cols-2" :class="loading && 'opacity-60'">
        <div v-for="c in items" :key="c.id" class="flex min-w-0 flex-col gap-3 rounded-xl border bg-card p-4" :class="!c.enabled && 'opacity-70'">
          <div class="flex items-center gap-2">
            <Icon name="bell" class="text-muted-foreground" />
            <span class="truncate font-medium">{{ c.name }}</span>
            <span class="text-xs text-muted-foreground uppercase">{{ CHANNEL_TYPES[c.type] }}</span>
            <StatusBadge v-if="c.type === 'mobile'" tone="info" label="Скоро" />
            <StatusBadge v-if="!c.enabled" tone="neutral" label="Выключен" />
            <span class="ml-auto flex shrink-0">
              <button :class="btnClass.ghostSm" title="Редактировать" @click="openForm(c)"><Icon name="pencil" /></button>
              <button :class="btnClass.ghostSm" title="Удалить" @click="removeError = ''; removing = c"><Icon name="trash" /></button>
            </span>
          </div>
          <p class="truncate text-sm text-muted-foreground" :title="summary(c)">{{ summary(c) }}</p>
          <div><button :class="btnClass.outlineSm" @click="runTest(c)"><Icon name="plug" />Отправить тест</button></div>
        </div>
      </div>
      <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="load" />
    </template>

    <Modal v-if="editing" wide :title="editing === 'new' ? 'Новый канал' : 'Редактировать канал'" @close="editing = null">
      <form class="grid gap-4" @submit.prevent="save">
        <div v-if="formError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ formError }}</div>
        <div class="grid gap-2">
          <label for="c-name" class="text-sm leading-none font-medium">Название</label>
          <input id="c-name" v-model="form.name" required maxlength="255" :class="inputClass" :aria-invalid="!!fieldErrors.name" />
          <p v-for="m in fieldErrors.name" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid gap-2">
          <span class="text-sm leading-none font-medium">Тип</span>
          <div v-if="editing === 'new'" class="flex flex-wrap gap-2">
            <button v-for="(label, k) in CHANNEL_TYPES" :key="k" type="button" :class="form.type === k ? btnClass.default : btnClass.outline" @click="form.type = k">{{ label }}<span v-if="k === 'mobile'" class="text-xs opacity-70">(скоро)</span></button>
          </div>
          <p v-else class="text-sm">{{ CHANNEL_TYPES[form.type] }} <span class="text-muted-foreground">(тип менять нельзя)</span></p>
          <p v-for="m in fieldErrors.type" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <template v-if="form.type === 'telegram'">
          <div class="grid gap-2">
            <label for="c-token" class="text-sm leading-none font-medium">Токен бота</label>
            <input id="c-token" v-model="form.bot_token" type="password" autocomplete="new-password" class="font-mono" :required="editing === 'new'" :placeholder="editing !== 'new' && editing.settings.has_bot_token ? 'Оставьте пустым, чтобы не менять' : '123456:ABC…'" :class="inputClass" :aria-invalid="fe('bot_token').length > 0" />
            <p v-for="m in fe('bot_token')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div class="grid gap-2">
            <label for="c-chat" class="text-sm leading-none font-medium">chat_id</label>
            <input id="c-chat" v-model="form.chat_id" required class="font-mono" placeholder="-1001234567890" :class="inputClass" :aria-invalid="fe('chat_id').length > 0" />
            <p v-for="m in fe('chat_id')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <details class="rounded-lg border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
            <summary class="cursor-pointer font-medium text-foreground">Как получить токен и chat_id</summary>
            <ol class="mt-2 list-decimal space-y-1 pl-4">
              <li>В Telegram откройте <code class="rounded bg-muted px-1 py-0.5 font-mono text-[13px]">@BotFather</code>, выполните <code class="rounded bg-muted px-1 py-0.5 font-mono text-[13px]">/newbot</code> и скопируйте токен.</li>
              <li>Напишите боту любое сообщение (или добавьте его в группу и напишите там).</li>
              <li>Откройте <code class="rounded bg-muted px-1 py-0.5 font-mono text-[13px] break-all">https://api.telegram.org/bot&lt;токен&gt;/getUpdates</code> и найдите <code class="rounded bg-muted px-1 py-0.5 font-mono text-[13px]">chat.id</code>. У групп он отрицательный.</li>
              <li>Сохраните канал и нажмите «Отправить тест».</li>
            </ol>
          </details>
        </template>
        <div v-else-if="form.type === 'email'" class="grid gap-2">
          <label for="c-mail" class="text-sm leading-none font-medium">Адреса получателей</label>
          <textarea id="c-mail" v-model="form.recipients" required rows="3" placeholder="admin@example.com" :class="inputClass" class="h-auto py-2" :aria-invalid="fe('recipients').length > 0" />
          <p class="text-xs text-muted-foreground">По одному в строке или через запятую.</p>
          <p v-for="m in [...fe('recipients'), ...Object.entries(fieldErrors).filter(([k]) => k.includes('recipients.')).flatMap(([, v]) => v)]" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <p v-else-if="form.type === 'webpush'" class="rounded-lg border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">Настройки не нужны: сообщения уходят во все браузеры, где включены уведомления (кнопка вверху страницы).</p>
        <p v-else class="rounded-lg border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">Мобильное приложение — скоро. Канал можно создать заранее, но отправки пока будут пропускаться.</p>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.enabled" type="checkbox" class="size-4 rounded-[4px] border-input accent-primary" />Включён</label>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" :class="btnClass.outline" @click="editing = null">Отмена</button>
          <button type="submit" :class="btnClass.default" :disabled="saving"><Icon v-if="saving" name="loader" class="animate-spin" />Сохранить</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="testing" :title="`Тест: ${testing.name}`" @close="testLoading || (testing = null)">
      <div v-if="testLoading" class="flex items-center gap-2 rounded-lg border px-4 py-3 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Отправляем…</div>
      <div v-else-if="testError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ testError }}</div>
      <template v-else-if="testResult">
        <div>
          <StatusBadge v-if="testResult.skipped" tone="neutral" label="Пропущено" />
          <StatusBadge v-else :tone="testResult.ok ? 'success' : 'danger'" :label="testResult.ok ? 'Тестовое сообщение отправлено' : 'Отправка не удалась'" />
        </div>
        <div v-if="testResult.error" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ testResult.error }}</div>
      </template>
      <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <button type="button" :class="btnClass.outline" :disabled="testLoading" @click="testing = null">Закрыть</button>
        <button type="button" :class="btnClass.default" :disabled="testLoading" @click="runTest(testing)"><Icon name="refresh" :class="testLoading && 'animate-spin'" />Повторить</button>
      </div>
    </Modal>

    <ConfirmDialog v-if="removing" title="Удалить канал?" :text="`Канал «${removing.name}» будет удалён и исчезнет из правил.`" :loading="removeLoading" :error="removeError" @confirm="remove" @close="removing = null" />
  </div>
</template>
