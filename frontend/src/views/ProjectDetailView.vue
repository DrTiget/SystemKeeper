<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import type { DatabaseConnection } from '@/api/databases'
import type { Project } from '@/api/projects'
import { type Backup, BACKUP_STATUS as STATUS, backupTrigger } from '@/api/backups'
import type { Server } from '@/api/servers'
import type { Monitor } from '@/api/monitors'
import MonitorsTable from '@/components/monitors/MonitorsTable.vue'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import ProjectFormModal from '@/components/ProjectFormModal.vue'
import SelectMenu from '@/components/SelectMenu.vue'
import DatabaseFormModal from '@/components/databases/DatabaseFormModal.vue'
import ServerFormModal from '@/components/servers/ServerFormModal.vue'
import ServersTable from '@/components/servers/ServersTable.vue'
import DatabasesTable from '@/components/databases/DatabasesTable.vue'
import { btnClass, formatBytes, formatDate } from '@/lib/ui'
import { useProjectsStore } from '@/stores/projects'

const route = useRoute()
const router = useRouter()
const id = Number(route.params.id)

const project = ref<Project | null>(null)
const servers = ref<Server[]>([])
const databases = ref<DatabaseConnection[]>([])
const backups = ref<Backup[]>([])
const monitors = ref<Monitor[]>([])
const loadError = ref('')
const store = useProjectsStore()

async function load() {
  try {
    const [p, s, d, b, m] = await Promise.all([
      api<{ data: Project }>(`/projects/${id}`),
      api<{ data: Server[] }>(`/servers?project_id=${id}&per_page=100`),
      api<{ data: DatabaseConnection[] }>(`/databases?project_id=${id}&per_page=100`),
      api<{ data: Backup[] }>(`/projects/${id}/backups?per_page=15`),
      // мониторинг — отдельный модуль: если он недоступен, остальная страница проекта всё равно работает
      api<{ data: Monitor[] }>(`/monitors?project_id=${id}&per_page=100`).catch(() => ({ data: [] as Monitor[] })),
    ])
    monitors.value = m.data
    backups.value = b.data
    store.crumbs = [{ label: 'Проекты', to: '/projects' }, { label: p.data.name }]
    project.value = p.data
    servers.value = s.data
    databases.value = d.data
  } catch (e) {
    loadError.value = e instanceof ApiError && e.status === 404 ? 'Проект не найден' : 'Не удалось загрузить проект'
  }
}

// Добавление существующих серверов и баз без проекта
const attach = ref<{ kind: 'server' | 'database'; options: { value: number; label: string; hint?: string }[] } | null>(null)
const attachId = ref<number>()
const attachError = ref('')

async function openAttach(kind: 'server' | 'database') {
  attachError.value = ''
  attachId.value = undefined
  const path = kind === 'server' ? '/servers' : '/databases'
  const items = (await api<{ data: (Server | DatabaseConnection)[] }>(`${path}?project_id=none&per_page=100`)).data
  attach.value = { kind, options: items.map((i) => ({ value: i.id, label: i.name, hint: 'project' in i && i.project ? `в проекте ${i.project.name}` : undefined })) }
}

async function doAttach() {
  if (!attach.value || !attachId.value) return
  try {
    const path = attach.value.kind === 'server' ? `/servers/${attachId.value}` : `/databases/${attachId.value}`
    await api(path, { method: 'PATCH', body: { project_id: id } })
    attach.value = null
    await load()
  } catch (e) {
    attachError.value = e instanceof ApiError ? e.message : 'Не удалось добавить'
  }
}

async function detach(kind: 'server' | 'database', itemId: number) {
  await api(kind === 'server' ? `/servers/${itemId}` : `/databases/${itemId}`, { method: 'PATCH', body: { project_id: null } })
  await load()
}

const editOpen = ref(false)
async function onEdited(p: Project) {
  editOpen.value = false
  await store.load()
  await load()
}
const createServerOpen = ref(false)
const createDbOpen = ref(false)
const deleteOpen = ref(false)

async function destroy() {
  await api(`/projects/${id}`, { method: 'DELETE' })
  await store.load()
  router.push({ name: 'projects' })
}

const empty = computed(() => !servers.value.length && !databases.value.length)

onMounted(load)

const card = 'rounded-xl border bg-card p-6 shadow-xs'
const backupLink = (b: Backup) => ({ name: 'backup', params: { id: b.id } })
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <RouterLink to="/projects" class="inline-flex items-center gap-1.5 self-start text-sm text-muted-foreground hover:text-foreground">
      <Icon name="arrow-left" class="size-4" />Все проекты
    </RouterLink>
    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>

    <template v-if="project">
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="space-y-1">
          <h2 class="text-xl font-semibold tracking-tight">{{ project.name }}</h2>
          <p v-if="project.description" class="text-sm text-muted-foreground">{{ project.description }}</p>
        </div>
        <div class="flex gap-2">
          <button :class="btnClass.outline" @click="editOpen = true"><Icon name="pencil" />Изменить</button>
          <button :class="btnClass.destructive" @click="deleteOpen = true"><Icon name="trash" />Удалить</button>
        </div>
      </header>

      <section :class="card" class="grid gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h3 class="font-semibold">Серверы <span class="font-normal text-muted-foreground">{{ servers.length }}</span></h3>
          <div class="flex gap-2">
            <button :class="btnClass.outline" @click="openAttach('server')">Добавить существующий</button>
            <button :class="btnClass.primary" @click="createServerOpen = true"><Icon name="plus" />Новый сервер</button>
          </div>
        </div>
        <ServersTable :items="servers" :show-project="false" empty-text="Серверов в проекте нет">
          <template #actions="{ item }">
            <button class="rounded p-1 hover:bg-accent hover:text-foreground" title="Убрать из проекта" @click="detach('server', item.id)"><Icon name="x" class="size-4" /></button>
          </template>
        </ServersTable>
      </section>

      <section :class="card" class="grid gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h3 class="font-semibold">Базы данных <span class="font-normal text-muted-foreground">{{ databases.length }}</span></h3>
          <div class="flex gap-2">
            <button :class="btnClass.outline" @click="openAttach('database')">Добавить существующую</button>
            <button :class="btnClass.primary" @click="createDbOpen = true"><Icon name="plus" />Новая база</button>
          </div>
        </div>
        <DatabasesTable :items="databases" :show-project="false" empty-text="Баз данных в проекте нет">
          <template #actions="{ item }">
            <button class="rounded p-1 hover:bg-accent hover:text-foreground" title="Убрать из проекта" @click="detach('database', item.id)"><Icon name="x" class="size-4" /></button>
          </template>
        </DatabasesTable>
      </section>

      <section :class="card" class="grid gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h3 class="font-semibold">Аптайм <span class="font-normal text-muted-foreground">{{ monitors.length }}</span></h3>
          <RouterLink :to="{ name: 'monitor-new', query: { project_id: id } }" :class="btnClass.primary"><Icon name="plus" />Новый монитор</RouterLink>
        </div>
        <MonitorsTable :items="monitors" :show-project="false" empty-text="Мониторов в проекте нет — добавьте сайт или API, чтобы следить за доступностью" />
      </section>

      <section :class="card" class="grid gap-4">
        <div class="grid gap-1">
          <h3 class="font-semibold">Последние бекапы</h3>
          <p class="text-sm text-muted-foreground">Всех серверов и баз проекта. Запуск — на странице сервера или базы.</p>
        </div>
        <div class="overflow-x-auto rounded-xl border bg-card">
          <table class="w-full text-sm">
            <thead class="border-b text-left text-muted-foreground">
              <tr>
                <th class="px-4 py-3 font-medium">Что</th>
                <th class="px-4 py-3 font-medium">Статус</th>
                <th class="px-4 py-3 font-medium">Когда</th>
                <th class="px-4 py-3 font-medium">Запуск</th>
                <th class="px-4 py-3 text-right font-medium">Размер</th>
                <th class="w-8 px-2" />
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="b in backups" :key="b.id" class="cursor-pointer hover:bg-accent/40" @click="router.push(backupLink(b))">
                <td class="px-4 py-3">
                  <span class="inline-flex items-center gap-2 font-medium"><Icon :name="b.source_type === 'server' ? 'server' : 'database'" class="size-4 text-muted-foreground" />{{ b.source?.name }}</span>
                </td>
                <td class="px-4 py-3">
                  <span class="inline-flex items-center gap-1.5" :class="b.status === 'failed' && 'text-destructive'" :title="b.error ?? ''"><span class="size-2 rounded-full" :class="STATUS[b.status].dot" />{{ STATUS[b.status].label }}</span>
                </td>
                <td class="px-4 py-3 whitespace-nowrap">{{ formatDate(b.started_at ?? b.created_at) }}</td>
                <td class="px-4 py-3 text-muted-foreground">{{ backupTrigger(b) }}</td>
                <td class="px-4 py-3 text-right whitespace-nowrap">{{ b.size !== null ? formatBytes(b.size) : '—' }}</td>
                <td class="px-2 text-muted-foreground"><Icon name="chevron-right" class="size-4" /></td>
              </tr>
              <tr v-if="!backups.length">
                <td colspan="6" class="px-4 py-10 text-center text-muted-foreground">Бекапов ещё не было</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <ProjectFormModal v-if="editOpen && project" :project="project" @close="editOpen = false" @saved="onEdited" />
    <ServerFormModal v-if="createServerOpen" :server="null" :project-id="id" @close="createServerOpen = false" @saved="(s) => router.push({ name: 'server', params: { id: s.id } })" />
    <DatabaseFormModal
      v-if="createDbOpen"
      :connection="null"
      :project-id="id"
      @close="createDbOpen = false"
      @saved="(c, provision) => router.push({ name: 'database', params: { id: c.id }, query: provision ? { provision: '1' } : { check: '1' } })"
    />

    <Modal v-if="attach" :title="attach.kind === 'server' ? 'Добавить сервер в проект' : 'Добавить базу данных в проект'" @close="attach = null">
      <div class="grid gap-4">
        <template v-if="attach.options.length">
          <SelectMenu v-model="attachId" :options="attach.options" placeholder="Выберите" />
          <p class="text-sm text-muted-foreground">В списке — {{ attach.kind === 'server' ? 'серверы' : 'базы данных' }} без проекта.</p>
        </template>
        <p v-else class="text-sm text-muted-foreground">Все {{ attach.kind === 'server' ? 'серверы' : 'базы данных' }} уже в проектах.</p>
        <p v-if="attachError" class="text-sm text-destructive">{{ attachError }}</p>
        <div class="flex justify-end gap-2">
          <button :class="btnClass.outline" @click="attach = null">Отмена</button>
          <button :class="btnClass.primary" :disabled="!attachId" @click="doAttach">Добавить</button>
        </div>
      </div>
    </Modal>

    <Modal v-if="deleteOpen && project" title="Удалить проект?" @close="deleteOpen = false">
      <p class="text-sm text-muted-foreground">
        Проект «{{ project.name }}» будет удалён. {{ empty ? '' : 'Его серверы и базы данных останутся — просто без проекта.' }}
      </p>
      <div class="mt-6 flex justify-end gap-2">
        <button :class="btnClass.outline" @click="deleteOpen = false">Отмена</button>
        <button :class="btnClass.destructive" @click="destroy">Удалить</button>
      </div>
    </Modal>
  </div>
</template>
