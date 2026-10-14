<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import { type DatabaseConnection, ENGINE_LABELS } from '@/api/databases'
import type { Project } from '@/api/projects'
import type { Server } from '@/api/servers'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import ProjectFormModal from '@/components/ProjectFormModal.vue'
import SelectMenu from '@/components/SelectMenu.vue'
import DatabaseFormModal from '@/components/databases/DatabaseFormModal.vue'
import ServerFormModal from '@/components/servers/ServerFormModal.vue'
import { describeCron } from '@/lib/cron'
import { btnClass } from '@/lib/ui'

const route = useRoute()
const router = useRouter()
const id = Number(route.params.id)

const project = ref<Project | null>(null)
const servers = ref<Server[]>([])
const databases = ref<DatabaseConnection[]>([])
const loadError = ref('')

async function load() {
  try {
    const [p, s, d] = await Promise.all([
      api<{ data: Project }>(`/projects/${id}`),
      api<{ data: Server[] }>(`/servers?project_id=${id}&per_page=100`),
      api<{ data: DatabaseConnection[] }>(`/databases?project_id=${id}&per_page=100`),
    ])
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
const createServerOpen = ref(false)
const createDbOpen = ref(false)
const deleteOpen = ref(false)

async function destroy() {
  await api(`/projects/${id}`, { method: 'DELETE' })
  router.push({ name: 'projects' })
}

const empty = computed(() => !servers.value.length && !databases.value.length)

onMounted(load)

const card = 'rounded-xl border bg-card p-6 shadow-xs'
const row = 'flex items-center gap-3 px-4 py-3 hover:bg-accent/40'
const dot = (status: string | null) => (status === 'ok' ? 'bg-emerald-500' : status === 'failed' ? 'bg-destructive' : 'bg-muted-foreground/40')
</script>

<template>
  <div class="flex max-w-4xl flex-col gap-6 p-4">
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
        <ul v-if="servers.length" class="divide-y rounded-lg border text-sm">
          <li v-for="s in servers" :key="s.id" :class="row">
            <span class="size-2 shrink-0 rounded-full" :class="dot(s.last_check.status)" />
            <RouterLink :to="{ name: 'server', params: { id: s.id } }" class="font-medium hover:underline">{{ s.name }}</RouterLink>
            <span class="font-mono text-xs text-muted-foreground">{{ s.host }}</span>
            <span class="ml-auto text-xs text-muted-foreground">{{ s.backup?.enabled ? describeCron(s.backup.schedule ?? '') : 'бекап не настроен' }}</span>
            <button class="text-muted-foreground hover:text-foreground" title="Убрать из проекта" @click="detach('server', s.id)"><Icon name="x" class="size-4" /></button>
          </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">Серверов в проекте нет.</p>
      </section>

      <section :class="card" class="grid gap-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h3 class="font-semibold">Базы данных <span class="font-normal text-muted-foreground">{{ databases.length }}</span></h3>
          <div class="flex gap-2">
            <button :class="btnClass.outline" @click="openAttach('database')">Добавить существующую</button>
            <button :class="btnClass.primary" @click="createDbOpen = true"><Icon name="plus" />Новая база</button>
          </div>
        </div>
        <ul v-if="databases.length" class="divide-y rounded-lg border text-sm">
          <li v-for="c in databases" :key="c.id" :class="row">
            <span class="size-2 shrink-0 rounded-full" :class="dot(c.last_check.status)" />
            <RouterLink :to="{ name: 'database', params: { id: c.id } }" class="font-medium hover:underline">{{ c.name }}</RouterLink>
            <span class="text-xs text-muted-foreground">{{ ENGINE_LABELS[c.engine] }}<template v-if="c.server"> · через {{ c.server.name }}</template></span>
            <span class="ml-auto text-xs text-muted-foreground">{{ c.backup.enabled ? describeCron(c.backup.schedule) : 'бекап не настроен' }}</span>
            <button class="text-muted-foreground hover:text-foreground" title="Убрать из проекта" @click="detach('database', c.id)"><Icon name="x" class="size-4" /></button>
          </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">Баз данных в проекте нет.</p>
      </section>
    </template>

    <ProjectFormModal v-if="editOpen && project" :project="project" @close="editOpen = false" @saved="(p) => ((project = p), (editOpen = false))" />
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
