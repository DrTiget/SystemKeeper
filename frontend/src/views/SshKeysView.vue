<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { Paginated, SshKey } from '@/api/types'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import CopyButton from '@/components/CopyButton.vue'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import Pagination from '@/components/Pagination.vue'
import { btnClass, errorMessage, formatDate, inputClass, textareaClass } from '@/lib/format'

const items = ref<SshKey[]>([])
const meta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
const loading = ref(true)
const loadError = ref('')

async function load(page = meta.value.current_page) {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api<Paginated<SshKey>>(`/ssh-keys?page=${page}&per_page=15`)
    // страница могла опустеть после удаления
    if (!res.data.length && res.meta.last_page > 0 && page > res.meta.last_page) return load(res.meta.last_page)
    items.value = res.data
    meta.value = res.meta
  } catch (e) {
    loadError.value = errorMessage(e, 'Не удалось загрузить список ключей')
  } finally {
    loading.value = false
  }
}
onMounted(() => load(1))

// --- создание / переименование ---
const editing = ref<'new' | SshKey | null>(null)
const mode = ref<'generate' | 'import'>('generate')
const form = ref({ name: '', type: 'ed25519', private_key: '', passphrase: '' })
const saving = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

function openNew() {
  form.value = { name: '', type: 'ed25519', private_key: '', passphrase: '' }
  mode.value = 'generate'
  formError.value = ''
  fieldErrors.value = {}
  editing.value = 'new'
}
function openEdit(k: SshKey) {
  form.value = { name: k.name, type: k.type, private_key: '', passphrase: '' }
  formError.value = ''
  fieldErrors.value = {}
  editing.value = k
}

async function save() {
  saving.value = true
  formError.value = ''
  fieldErrors.value = {}
  try {
    const target = editing.value
    if (target === 'new') {
      const body: Record<string, unknown> = { name: form.value.name }
      if (mode.value === 'generate') body.type = form.value.type
      else {
        body.private_key = form.value.private_key
        if (form.value.passphrase) body.passphrase = form.value.passphrase
      }
      await api('/ssh-keys', { method: 'POST', body })
    } else if (target) {
      await api(`/ssh-keys/${target.id}`, { method: 'PUT', body: { name: form.value.name } })
    }
    editing.value = null
    form.value.private_key = form.value.passphrase = ''
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

// --- публичный ключ ---
const pub = ref<{ key: SshKey; public_key: string; fingerprint: string } | null>(null)
const pubError = ref('')
async function showPublic(k: SshKey) {
  pubError.value = ''
  pub.value = { key: k, public_key: k.public_key, fingerprint: k.fingerprint }
  try {
    const res = await api<{ data: { public_key: string; fingerprint: string } }>(`/ssh-keys/${k.id}/public`)
    pub.value = { key: k, ...res.data }
  } catch (e) {
    pubError.value = errorMessage(e, 'Не удалось получить публичный ключ')
  }
}

// --- удаление ---
const removing = ref<SshKey | null>(null)
const removeError = ref('')
const removeLoading = ref(false)
function askRemove(k: SshKey) {
  removeError.value = ''
  removing.value = k
}
async function remove() {
  if (!removing.value) return
  removeLoading.value = true
  removeError.value = ''
  try {
    await api(`/ssh-keys/${removing.value.id}`, { method: 'DELETE' })
    removing.value = null
    await load()
  } catch (e) {
    removeError.value =
      e instanceof ApiError && e.status === 409
        ? 'Ключ используется серверами — сначала переключите их на другой ключ или пароль'
        : errorMessage(e)
  } finally {
    removeLoading.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <div class="flex items-start justify-between gap-4">
      <header class="space-y-0.5">
        <h2 class="text-xl font-semibold tracking-tight">SSH-ключи</h2>
        <p class="text-sm text-muted-foreground">Ключи для подключения к серверам. Закрытая часть хранится зашифрованной и никогда не отдаётся.</p>
      </header>
      <button :class="btnClass.default" @click="openNew"><Icon name="plus" />Добавить ключ</button>
    </div>

    <div v-if="loadError" role="alert" class="flex items-center gap-3 rounded-lg border bg-card px-4 py-3 text-sm text-destructive">
      {{ loadError }}
      <button :class="[btnClass.outlineSm, 'ml-auto']" @click="load()">Повторить</button>
    </div>
    <div v-else-if="loading && !items.length" class="rounded-xl border p-10 text-center text-sm text-muted-foreground">Загрузка…</div>
    <div v-else-if="!items.length" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
      Ключей пока нет. Сгенерируйте новый или импортируйте существующий.
    </div>
    <template v-else>
      <div class="overflow-x-auto rounded-xl border p-4" :class="loading && 'opacity-60'">
        <table class="w-full text-sm">
          <thead class="text-left text-xs text-muted-foreground">
            <tr>
              <th class="pb-2 font-normal">Название</th>
              <th class="pb-2 font-normal">Тип</th>
              <th class="pb-2 font-normal">Отпечаток</th>
              <th class="pb-2 text-right font-normal">Серверов</th>
              <th class="pb-2 font-normal">Создан</th>
              <th class="pb-2" />
            </tr>
          </thead>
          <tbody class="tabular-nums">
            <tr v-for="k in items" :key="k.id" class="border-t">
              <td class="max-w-56 truncate py-1.5 pr-2 font-mono font-medium">
                <Icon name="key" class="mr-1.5 inline text-muted-foreground" />{{ k.name }}
                <span v-if="k.has_passphrase" class="ml-1 rounded-full border px-1.5 py-0.5 font-sans text-[11px] font-normal text-muted-foreground">passphrase</span>
              </td>
              <td class="py-1.5 pr-2 text-xs text-muted-foreground uppercase">{{ k.type }}</td>
              <td class="max-w-64 truncate py-1.5 pr-2 font-mono text-xs" :title="k.fingerprint">{{ k.fingerprint }}</td>
              <td class="py-1.5 pr-2 text-right">{{ k.servers_count ?? 0 }}</td>
              <td class="py-1.5 pr-2 text-xs whitespace-nowrap text-muted-foreground">{{ formatDate(k.created_at) }}</td>
              <td class="py-1.5 text-right whitespace-nowrap">
                <button :class="btnClass.ghostSm" title="Публичный ключ" @click="showPublic(k)"><Icon name="eye" /></button>
                <button :class="btnClass.ghostSm" title="Переименовать" @click="openEdit(k)"><Icon name="pencil" /></button>
                <button :class="btnClass.ghostSm" title="Удалить" @click="askRemove(k)"><Icon name="trash" /></button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="load" />
    </template>

    <Modal
      v-if="editing"
      wide
      :title="editing === 'new' ? 'Новый SSH-ключ' : 'Переименовать ключ'"
      :description="editing === 'new' ? 'Сгенерируйте пару ключей или импортируйте существующий закрытый ключ.' : undefined"
      @close="editing = null"
    >
      <form class="grid gap-4" @submit.prevent="save">
        <div v-if="formError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ formError }}</div>
        <div v-if="editing === 'new'" class="flex gap-2">
          <button type="button" :class="mode === 'generate' ? btnClass.default : btnClass.outline" @click="mode = 'generate'">Сгенерировать</button>
          <button type="button" :class="mode === 'import' ? btnClass.default : btnClass.outline" @click="mode = 'import'">Импортировать</button>
        </div>
        <div class="grid gap-2">
          <label for="k-name" class="text-sm leading-none font-medium">Название</label>
          <input id="k-name" v-model="form.name" required maxlength="255" class="font-mono" :class="inputClass" :aria-invalid="!!fieldErrors.name" />
          <p v-for="m in fieldErrors.name" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <template v-if="editing === 'new' && mode === 'generate'">
          <div class="grid gap-2">
            <label for="k-type" class="text-sm leading-none font-medium">Тип</label>
            <select id="k-type" v-model="form.type" :class="inputClass">
              <option value="ed25519">ed25519 (рекомендуется)</option>
              <option value="rsa">RSA, 4096 бит (генерация может занять несколько секунд)</option>
            </select>
            <p v-for="m in fieldErrors.type" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
        </template>
        <template v-else-if="editing === 'new'">
          <div class="grid gap-2">
            <label for="k-priv" class="text-sm leading-none font-medium">Закрытый ключ</label>
            <textarea id="k-priv" v-model="form.private_key" required spellcheck="false" autocomplete="off" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" class="font-mono text-xs" :class="textareaClass" :aria-invalid="!!fieldErrors.private_key" />
            <p v-for="m in fieldErrors.private_key" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div class="grid gap-2">
            <label for="k-pass" class="text-sm leading-none font-medium">Парольная фраза</label>
            <input id="k-pass" v-model="form.passphrase" type="password" autocomplete="new-password" :class="inputClass" :aria-invalid="!!fieldErrors.passphrase" />
            <p class="text-xs text-muted-foreground">Только если ключ защищён парольной фразой. Принимаются ed25519 и RSA.</p>
            <p v-for="m in fieldErrors.passphrase" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
        </template>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" :class="btnClass.outline" @click="editing = null">Отмена</button>
          <button type="submit" :class="btnClass.default" :disabled="saving">
            <Icon v-if="saving" name="loader" class="animate-spin" />
            {{ editing !== 'new' ? 'Сохранить' : mode === 'generate' ? 'Сгенерировать' : 'Импортировать' }}
          </button>
        </div>
      </form>
    </Modal>

    <Modal v-if="pub" wide :title="`Публичный ключ «${pub.key.name}»`" description="Добавьте его в ~/.ssh/authorized_keys на сервере." @close="pub = null">
      <div v-if="pubError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ pubError }}</div>
      <code class="block rounded-md bg-muted/40 px-2 py-1.5 text-xs break-all select-all">{{ pub.public_key }}</code>
      <p class="font-mono text-xs break-all text-muted-foreground">{{ pub.fingerprint }}</p>
      <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <button type="button" :class="btnClass.outline" @click="pub = null">Закрыть</button>
        <CopyButton :text="pub.public_key" />
      </div>
    </Modal>

    <ConfirmDialog
      v-if="removing"
      title="Удалить ключ?"
      :text="`Ключ «${removing.name}» будет удалён без возможности восстановления.`"
      :loading="removeLoading"
      :error="removeError"
      @confirm="remove"
      @close="removing = null"
    />
  </div>
</template>
