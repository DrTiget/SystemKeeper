<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import { type DatabaseConnection, type DbSchema, ENGINE_LABELS } from '@/api/databases'
import Icon from '@/components/Icon.vue'
import BackupHistory from '@/components/BackupHistory.vue'
import Modal from '@/components/Modal.vue'
import DatabaseBackupForm from '@/components/databases/DatabaseBackupForm.vue'
import DatabaseFormModal from '@/components/databases/DatabaseFormModal.vue'
import ProvisionUserModal from '@/components/databases/ProvisionUserModal.vue'
import { useProjectsStore } from '@/stores/projects'
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
const projects = useProjectsStore()
watch(connection, (c) => c && projects.setFor(c.project, { label: 'Без проекта' }, c.name))
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
  <div class="flex max-w-7xl flex-col gap-6 p-4">
    <RouterLink :to="connection?.project ? `/projects/${connection.project.id}` : '/projects'" class="inline-flex items-center gap-1.5 self-start text-sm text-muted-foreground hover:text-foreground">
      <Icon name="arrow-left" class="size-4" />{{ connection?.project ? connection.project.name : 'Все проекты' }}
    </RouterLink>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>
    <p v-else-if="!connection" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>

    <template v-if="connection">
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="space-y-1">
          <h2 class="flex items-center gap-2 text-xl font-semibold tracking-tight"><Icon name="database" class="size-5 text-muted-foreground" />{{ connection.name }}</h2>
          <p class="text-sm text-muted-foreground">{{ connection.description || ENGINE_LABELS[connection.engine] }}</p>
        </div>
        <button :class="btnClass.destructive" @click="(deleteOpen = true), (deleteError = '')"><Icon name="trash" />Удалить базу</button>
      </header>

      <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <section :class="card" class="grid gap-6">
          <div class="grid gap-1">
            <h3 class="font-semibold">Настройки бекапа</h3>
            <p class="text-sm text-muted-foreground">Какие базы дампить, когда и сколько хранить.</p>
          </div>
          <!-- пока пользователь для бекапов создаётся, к СУБД подключаться ещё нечем -->
          <p v-if="provisionOpen && !connection.has_password" class="text-sm text-muted-foreground">Настройки станут доступны после создания пользователя.</p>
          <DatabaseBackupForm v-else :key="formKey" :connection="connection" @saved="connection = $event" />
        </section>

        <aside class="grid gap-6">
          <section :class="card" class="p-4!">
            <BackupHistory :source="`databases/${connection.id}`" />
          </section>

          <section :class="card" class="grid gap-4 p-4!">
            <div class="flex items-center justify-between gap-2">
              <h3 class="font-semibold">Подключение</h3>
              <button :class="btnClass.outline" class="h-8! px-3!" @click="editOpen = true"><Icon name="pencil" />Изменить</button>
            </div>
            <dl class="grid gap-3 text-sm">
              <div class="grid gap-0.5">
                <dt :class="dt">Состояние</dt>
                <dd class="flex flex-wrap items-center gap-x-2">
                  <span v-if="checking" class="inline-flex items-center gap-1.5 text-muted-foreground"><Icon name="loader" class="size-4 animate-spin" />Проверка…</span>
                  <span v-else-if="connection.last_check.status === 'ok'" class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-emerald-500" />Работает</span>
                  <span v-else-if="connection.last_check.status === 'failed'" class="inline-flex items-center gap-1.5 text-destructive"><span class="size-2 rounded-full bg-destructive" />Ошибка</span>
                  <span v-else class="text-muted-foreground">Не проверялась</span>
                  <span v-if="connection.last_check.checked_at && !checking" class="text-xs text-muted-foreground">{{ formatDate(connection.last_check.checked_at) }}</span>
                </dd>
                <dd v-if="connection.last_check.status === 'failed' && !checking" class="text-xs break-words whitespace-pre-wrap text-destructive">{{ connection.last_check.error }}</dd>
                <dd v-else-if="checkResult?.status === 'ok' && checkResult.databases" class="text-xs text-muted-foreground">Баз на сервере: {{ checkResult.databases.length }}</dd>
              </div>
              <div class="grid gap-0.5">
                <dt :class="dt">СУБД</dt>
                <dd>{{ ENGINE_LABELS[connection.engine] }}<span v-if="connection.last_check.server_version" class="text-muted-foreground"> · {{ connection.last_check.server_version }}</span></dd>
              </div>
              <div class="grid gap-0.5">
                <dt :class="dt">Адрес</dt>
                <dd class="font-mono text-xs break-all">{{ connection.username }}@{{ connection.host }}:{{ connection.port }}</dd>
              </div>
              <div class="grid gap-0.5">
                <dt :class="dt">Как подключаемся</dt>
                <dd>
                  <template v-if="connection.via === 'ssh' && connection.server">
                    через сервер <RouterLink :to="{ name: 'server', params: { id: connection.server.id } }" class="underline underline-offset-2">{{ connection.server.name }}</RouterLink>
                    <template v-if="connection.docker_container">, контейнер <span class="font-mono text-xs">{{ connection.docker_container }}</span></template>
                  </template>
                  <template v-else>напрямую</template>
                </dd>
              </div>
            </dl>
            <div class="grid gap-2">
              <button :class="btnClass.outline" class="h-8! px-3!" :disabled="checking" @click="check"><Icon :name="checking ? 'loader' : 'plug'" :class="checking && 'animate-spin'" />Проверить подключение</button>
              <button v-if="connection.via === 'ssh'" :class="btnClass.outline" class="h-8! px-3!" title="Создать в СУБД пользователя только для чтения" @click="provisionOpen = true"><Icon name="users" />Создать пользователя для бекапов</button>
            </div>
          </section>
        </aside>
      </div>
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
