<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import type { CheckResult, Server } from '@/api/servers'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { type DatabaseConnection, ENGINE_LABELS } from '@/api/databases'
import DatabaseFormModal from '@/components/databases/DatabaseFormModal.vue'
import BackupSettingsForm from '@/components/servers/BackupSettingsForm.vue'
import CheckResultModal from '@/components/servers/CheckResultModal.vue'
import PublicKeyModal from '@/components/servers/PublicKeyModal.vue'
import ServerFormModal from '@/components/servers/ServerFormModal.vue'
import { btnClass, formatDate } from '@/lib/ui'

const route = useRoute()
const router = useRouter()
const id = Number(route.params.id)

const server = ref<Server | null>(null)
const loadError = ref('')

async function load() {
  try {
    server.value = (await api<{ data: Server }>(`/servers/${id}`)).data
  } catch (e) {
    loadError.value = e instanceof ApiError && e.status === 404 ? 'Сервер не найден' : 'Не удалось загрузить сервер'
  }
}

// Проверка подключения
const checking = ref(false)
const checkResult = ref<{ output: string | null; error: string | null } | null>(null)

async function check() {
  checking.value = true
  checkResult.value ??= { output: null, error: null }
  try {
    const res = (await api<{ data: CheckResult }>(`/servers/${id}/check`, { method: 'POST' })).data
    server.value = res.server
    checkResult.value = { output: res.output, error: res.error }
  } catch (e) {
    checkResult.value = { output: null, error: e instanceof ApiError ? e.message : 'Не удалось выполнить проверку' }
  } finally {
    checking.value = false
  }
}

async function resetFingerprint() {
  server.value = (await api<{ data: Server }>(`/servers/${id}/reset-fingerprint`, { method: 'POST' })).data
}

// Изменение подключения и ключ
const editOpen = ref(false)
const keyOpen = ref(false)

function onSaved(updated: Server, generatedKey: boolean) {
  server.value = updated
  editOpen.value = false
  if (generatedKey) keyOpen.value = true
}

// Базы данных, подключённые через этот сервер
const databases = ref<DatabaseConnection[]>([])
const dbCreateOpen = ref(false)

async function loadDatabases() {
  try {
    databases.value = (await api<{ data: DatabaseConnection[] }>(`/databases?server_id=${id}&per_page=100`)).data
  } catch {
    databases.value = []
  }
}

function onDatabaseCreated(c: DatabaseConnection) {
  dbCreateOpen.value = false
  router.push({ name: 'database', params: { id: c.id }, query: { check: '1' } })
}

// Удаление
const deleteOpen = ref(false)
const deleteError = ref('')

async function destroy() {
  deleteError.value = ''
  try {
    await api(`/servers/${id}`, { method: 'DELETE' })
    router.push({ name: 'servers' })
  } catch (e) {
    deleteError.value = e instanceof ApiError ? e.message : 'Не удалось удалить'
  }
}

onMounted(async () => {
  await load()
  loadDatabases()
  // После создания со сгенерированным ключом список передаёт ?key=1 — сразу показываем открытый ключ
  // а с готовыми учётными данными (?check=1) — сразу проверяем подключение
  if (route.query.key && server.value?.public_key) keyOpen.value = true
  else if (route.query.check && server.value) check()
  if (route.query.key || route.query.check) router.replace({ query: {} })
})

const card = 'rounded-xl border bg-card p-6 shadow-xs'
const dt = 'text-xs text-muted-foreground'
</script>

<template>
  <div class="flex max-w-4xl flex-col gap-6 p-4">
    <RouterLink to="/servers" class="inline-flex items-center gap-1.5 self-start text-sm text-muted-foreground hover:text-foreground">
      <Icon name="arrow-left" class="size-4" />Все серверы
    </RouterLink>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>
    <p v-else-if="!server" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>

    <template v-else>
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="space-y-1">
          <h2 class="text-xl font-semibold tracking-tight">{{ server.name }}</h2>
          <p v-if="server.description" class="text-sm text-muted-foreground">{{ server.description }}</p>
        </div>
        <button :class="btnClass.destructive" @click="(deleteOpen = true), (deleteError = '')"><Icon name="trash" />Удалить</button>
      </header>

      <section :class="card" class="grid gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h3 class="font-semibold">Подключение SSH</h3>
          <div class="flex flex-wrap gap-2">
            <button v-if="server.public_key" :class="btnClass.outline" @click="keyOpen = true"><Icon name="key" />Открытый ключ</button>
            <button :class="btnClass.outline" @click="editOpen = true"><Icon name="pencil" />Изменить</button>
            <button :class="btnClass.primary" :disabled="checking" @click="check"><Icon :name="checking ? 'loader' : 'plug'" :class="checking && 'animate-spin'" />Проверить</button>
          </div>
        </div>
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
          <div class="grid gap-0.5">
            <dt :class="dt">Адрес</dt>
            <dd class="font-mono text-xs">{{ server.username }}@{{ server.host }}:{{ server.port }}</dd>
          </div>
          <div class="grid gap-0.5">
            <dt :class="dt">Авторизация</dt>
            <dd>{{ server.auth_type === 'key' ? (server.has_passphrase ? 'Ключ с парольной фразой' : 'Ключ') : 'Пароль' }}</dd>
          </div>
          <div class="grid gap-0.5">
            <dt :class="dt">Состояние</dt>
            <dd>
              <span v-if="server.last_check.status === 'ok'" class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-emerald-500" />Работает</span>
              <span v-else-if="server.last_check.status === 'failed'" class="inline-flex items-center gap-1.5 text-destructive"><span class="size-2 rounded-full bg-destructive" />Ошибка</span>
              <span v-else class="text-muted-foreground">Не проверялся</span>
              <span v-if="server.last_check.checked_at" class="ml-1 text-xs text-muted-foreground">{{ formatDate(server.last_check.checked_at) }}</span>
            </dd>
          </div>
          <div v-if="server.last_check.status === 'failed'" class="grid gap-0.5 sm:col-span-3">
            <dt :class="dt">Ошибка</dt>
            <dd class="text-destructive">{{ server.last_check.error }}</dd>
          </div>
          <div v-if="server.host_fingerprint" class="grid gap-0.5 sm:col-span-3">
            <dt :class="dt">Отпечаток хоста</dt>
            <dd class="font-mono text-xs break-all">{{ server.host_fingerprint }}</dd>
          </div>
        </dl>
      </section>

      <section :class="card" class="grid gap-4">
        <div class="grid gap-1">
          <h3 class="font-semibold">Бекап файлов</h3>
          <p class="text-sm text-muted-foreground">Какие каталоги копировать, как часто и что выполнить перед этим.</p>
        </div>
        <BackupSettingsForm :server-id="server.id" />
      </section>

      <section :class="card" class="grid gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="grid gap-1">
            <h3 class="font-semibold">Базы данных</h3>
            <p class="text-sm text-muted-foreground">СУБД, которые дампятся через этот сервер.</p>
          </div>
          <button :class="btnClass.outline" @click="dbCreateOpen = true"><Icon name="plus" />Добавить базу</button>
        </div>
        <ul v-if="databases.length" class="divide-y rounded-md border text-sm">
          <li v-for="d in databases" :key="d.id">
            <RouterLink :to="{ name: 'database', params: { id: d.id } }" class="flex items-center gap-3 px-3 py-2 hover:bg-accent/40">
              <Icon name="database" class="size-4 text-primary" />
              <span class="font-medium">{{ d.name }}</span>
              <span class="text-xs text-muted-foreground">{{ ENGINE_LABELS[d.engine] }}<template v-if="d.docker_container"> · {{ d.docker_container }}</template></span>
              <span class="ml-auto text-xs" :class="d.backup.enabled ? '' : 'text-muted-foreground'">{{ d.backup.enabled ? 'бекап включён' : 'бекап не настроен' }}</span>
              <Icon name="chevron-right" class="size-4 text-muted-foreground" />
            </RouterLink>
          </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">Пока нет.</p>
      </section>
    </template>

    <ServerFormModal v-if="editOpen && server" :server="server" @close="editOpen = false" @saved="onSaved" @show-key="keyOpen = true" />
    <PublicKeyModal v-if="keyOpen && server" :server="server" @close="keyOpen = false" @check="(keyOpen = false), check()" />
    <CheckResultModal
      v-if="checkResult && server"
      :server="server"
      :output="checkResult.output"
      :error="checkResult.error"
      :checking="checking"
      @close="checkResult = null"
      @recheck="check"
      @reset-fingerprint="resetFingerprint"
    />

    <DatabaseFormModal v-if="dbCreateOpen && server" :connection="null" :server-id="server.id" @close="dbCreateOpen = false" @saved="onDatabaseCreated" />

    <Modal v-if="deleteOpen && server" title="Удалить сервер?" @close="deleteOpen = false">
      <p class="text-sm text-muted-foreground">{{ server.name }} ({{ server.host }}), его учётные данные SSH и настройки бекапа будут удалены.</p>
      <p v-if="deleteError" class="mt-3 text-sm text-destructive">{{ deleteError }}</p>
      <div class="mt-6 flex justify-end gap-2">
        <button :class="btnClass.outline" @click="deleteOpen = false">Отмена</button>
        <button :class="btnClass.destructive" @click="destroy">Удалить</button>
      </div>
    </Modal>
  </div>
</template>
