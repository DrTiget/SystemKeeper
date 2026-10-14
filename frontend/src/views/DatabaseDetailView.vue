<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import { type DatabaseConnection, type DbSchema, ENGINE_LABELS } from '@/api/databases'
import Icon from '@/components/Icon.vue'
import BackupHistory from '@/components/BackupHistory.vue'
import Modal from '@/components/Modal.vue'
import DatabaseBackupForm from '@/components/databases/DatabaseBackupForm.vue'
import DatabaseFormModal from '@/components/databases/DatabaseFormModal.vue'
import ProvisionUserModal from '@/components/databases/ProvisionUserModal.vue'
import { btnClass, formatDate } from '@/lib/ui'

interface CheckResult {
  status: 'ok' | 'failed'
  error: string | null
  version: string | null
  databases: DbSchema[] | null
  connection: DatabaseConnection
}

const route = useRoute()
const router = useRouter()
const id = Number(route.params.id)

const connection = ref<DatabaseConnection | null>(null)
const loadError = ref('')
// Форма бекапа пересоздаётся после изменения подключения — чтобы заново получить список баз
const formKey = ref(0)

async function load() {
  try {
    connection.value = (await api<{ data: DatabaseConnection }>(`/databases/${id}`)).data
  } catch (e) {
    loadError.value = e instanceof ApiError && e.status === 404 ? 'База данных не найдена' : 'Не удалось загрузить'
  }
}

// Проверка
const checking = ref(false)
const checkResult = ref<CheckResult | null>(null)

async function check() {
  checking.value = true
  try {
    const res = (await api<{ data: CheckResult }>(`/databases/${id}/check`, { method: 'POST' })).data
    checkResult.value = res
    connection.value = res.connection
    if (res.status === 'ok') formKey.value++
  } catch (e) {
    checkResult.value = null
    loadError.value = e instanceof ApiError ? e.message : 'Не удалось выполнить проверку'
  } finally {
    checking.value = false
  }
}

const editOpen = ref(false)
const provisionOpen = ref(false)

function onProvisioned(c: DatabaseConnection) {
  connection.value = c
  formKey.value++
}

function onSaved(c: DatabaseConnection) {
  connection.value = c
  editOpen.value = false
  check()
}

const deleteOpen = ref(false)
const deleteError = ref('')

async function destroy() {
  deleteError.value = ''
  try {
    await api(`/databases/${id}`, { method: 'DELETE' })
    router.push({ name: 'databases' })
  } catch (e) {
    deleteError.value = e instanceof ApiError ? e.message : 'Не удалось удалить'
  }
}

onMounted(async () => {
  await load()
  if (route.query.provision && connection.value) provisionOpen.value = true
  else if (route.query.check && connection.value) check()
  if (route.query.provision || route.query.check) router.replace({ query: {} })
})

const card = 'rounded-xl border bg-card p-6 shadow-xs'
const dt = 'text-xs text-muted-foreground'
</script>

<template>
  <div class="flex max-w-4xl flex-col gap-6 p-4">
    <RouterLink to="/databases" class="inline-flex items-center gap-1.5 self-start text-sm text-muted-foreground hover:text-foreground">
      <Icon name="arrow-left" class="size-4" />Все базы данных
    </RouterLink>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>
    <p v-else-if="!connection" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>

    <template v-if="connection">
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="space-y-1">
          <h2 class="text-xl font-semibold tracking-tight">{{ connection.name }}</h2>
          <p class="text-sm text-muted-foreground">{{ connection.description || ENGINE_LABELS[connection.engine] }}</p>
        </div>
        <button :class="btnClass.destructive" @click="(deleteOpen = true), (deleteError = '')"><Icon name="trash" />Удалить</button>
      </header>

      <section :class="card" class="grid gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h3 class="font-semibold">Подключение</h3>
          <div class="flex flex-wrap gap-2">
            <button v-if="connection.via === 'ssh'" :class="btnClass.outline" title="Создать в СУБД пользователя только для чтения" @click="provisionOpen = true"><Icon name="users" />Пользователь для бекапов</button>
            <button :class="btnClass.outline" @click="editOpen = true"><Icon name="pencil" />Изменить</button>
            <button :class="btnClass.primary" :disabled="checking" @click="check"><Icon :name="checking ? 'loader' : 'plug'" :class="checking && 'animate-spin'" />Проверить</button>
          </div>
        </div>
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
          <div class="grid gap-0.5">
            <dt :class="dt">СУБД</dt>
            <dd>{{ ENGINE_LABELS[connection.engine] }}</dd>
          </div>
          <div class="grid gap-0.5">
            <dt :class="dt">Адрес</dt>
            <dd class="font-mono text-xs">{{ connection.username }}@{{ connection.host }}:{{ connection.port }}</dd>
          </div>
          <div class="grid gap-0.5">
            <dt :class="dt">Как подключаемся</dt>
            <dd>
              <template v-if="connection.via === 'ssh' && connection.server">
                через <RouterLink :to="{ name: 'server', params: { id: connection.server.id } }" class="underline">{{ connection.server.name }}</RouterLink>
                <template v-if="connection.docker_container">, контейнер <span class="font-mono text-xs">{{ connection.docker_container }}</span></template>
              </template>
              <template v-else>напрямую</template>
            </dd>
          </div>
          <div class="grid gap-0.5">
            <dt :class="dt">Состояние</dt>
            <dd>
              <span v-if="checking" class="inline-flex items-center gap-1.5 text-muted-foreground"><Icon name="loader" class="size-4 animate-spin" />Проверка…</span>
              <span v-else-if="connection.last_check.status === 'ok'" class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-emerald-500" />Работает</span>
              <span v-else-if="connection.last_check.status === 'failed'" class="inline-flex items-center gap-1.5 text-destructive"><span class="size-2 rounded-full bg-destructive" />Ошибка</span>
              <span v-else class="text-muted-foreground">Не проверялась</span>
              <span v-if="connection.last_check.checked_at && !checking" class="ml-1 text-xs text-muted-foreground">{{ formatDate(connection.last_check.checked_at) }}</span>
            </dd>
          </div>
          <div v-if="connection.last_check.server_version" class="grid gap-0.5 sm:col-span-2">
            <dt :class="dt">Версия</dt>
            <dd class="truncate">{{ connection.last_check.server_version }}</dd>
          </div>
          <div v-if="connection.last_check.status === 'failed' && !checking" class="grid gap-0.5 sm:col-span-3">
            <dt :class="dt">Ошибка</dt>
            <dd class="whitespace-pre-wrap text-destructive">{{ connection.last_check.error }}</dd>
          </div>
        </dl>
        <p v-if="checkResult?.status === 'ok' && checkResult.databases" class="text-sm text-muted-foreground">
          Подключение работает, баз: {{ checkResult.databases.length }}.
        </p>
      </section>

      <section :class="card" class="grid gap-4">
        <div class="grid gap-1">
          <h3 class="font-semibold">Бекап</h3>
          <p class="text-sm text-muted-foreground">Какие базы и таблицы дампить, как часто и с какой блокировкой.</p>
        </div>
        <!-- пока пользователь для бекапов создаётся, к СУБД подключаться ещё нечем -->
        <p v-if="provisionOpen && !connection.has_password" class="text-sm text-muted-foreground">Настройки станут доступны после создания пользователя.</p>
        <DatabaseBackupForm v-else :key="formKey" :connection="connection" @saved="connection = $event" />
      </section>

      <section :class="card" class="grid gap-4">
        <div class="grid gap-1">
          <h3 class="font-semibold">Бекапы</h3>
          <p class="text-sm text-muted-foreground">Запуск вручную и последние дампы — по файлу на базу. Пока хранилищ нет, они лежат в SystemKeeper.</p>
        </div>
        <BackupHistory :source="`databases/${connection.id}`" />
      </section>
    </template>

    <DatabaseFormModal v-if="editOpen && connection" :connection="connection" @close="editOpen = false" @saved="onSaved" />

    <ProvisionUserModal v-if="provisionOpen && connection" :connection="connection" @close="provisionOpen = false" @done="onProvisioned" />

    <Modal v-if="deleteOpen && connection" title="Удалить базу данных?" @close="deleteOpen = false">
      <p class="text-sm text-muted-foreground">Подключение «{{ connection.name }}» и настройки его бекапа будут удалены. Сама база на сервере не затрагивается.</p>
      <p v-if="deleteError" class="mt-3 text-sm text-destructive">{{ deleteError }}</p>
      <div class="mt-6 flex justify-end gap-2">
        <button :class="btnClass.outline" @click="deleteOpen = false">Отмена</button>
        <button :class="btnClass.destructive" @click="destroy">Удалить</button>
      </div>
    </Modal>
  </div>
</template>
