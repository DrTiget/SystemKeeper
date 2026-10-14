<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import type { Project, ProjectGroup } from '@/api/projects'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import ProjectFormModal from '@/components/ProjectFormModal.vue'
import { btnClass, inputClass } from '@/lib/ui'
import { useProjectsStore } from '@/stores/projects'

const router = useRouter()
const route = useRoute()
const store = useProjectsStore()
const search = ref('')
const loadError = ref('')

async function load() {
  try {
    await store.load()
  } catch {
    loadError.value = 'Не удалось загрузить проекты'
  }
}

// Поиск — по уже загруженным проектам; пустые группы при поиске скрываются
const sections = computed(() => {
  const q = search.value.trim().toLowerCase()
  const match = (p: Project) => !q || p.name.toLowerCase().includes(q) || (p.description ?? '').toLowerCase().includes(q)
  return store.grouped
    .map((g) => ({ ...g, projects: g.projects.filter(match) }))
    .filter((g) => (g.group ? !q || g.projects.length : g.projects.length))
})

// Создание проекта: «+» в меню открывает /projects?new=1
const createFor = ref<{ groupId: number | null } | null>(route.query.new ? { groupId: null } : null)
watch(() => route.query.new, (v) => v && (createFor.value = { groupId: null }))
function closeCreate() {
  createFor.value = null
  if (route.query.new) router.replace({ query: {} })
}
async function onCreated(p: Project) {
  await store.load()
  router.push({ name: 'project', params: { id: p.id } })
}

// Группы: создание, переименование, удаление
const groupEdit = ref<{ group: ProjectGroup | null; name: string } | null>(null)
const groupError = ref('')
const groupDelete = ref<ProjectGroup | null>(null)

async function saveGroup() {
  const g = groupEdit.value!
  groupError.value = ''
  try {
    if (g.group) await api(`/project-groups/${g.group.id}`, { method: 'PATCH', body: { name: g.name } })
    else await api('/project-groups', { method: 'POST', body: { name: g.name } })
    groupEdit.value = null
    await load()
  } catch (e) {
    groupError.value = e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'Не удалось сохранить'
  }
}

async function deleteGroup() {
  await api(`/project-groups/${groupDelete.value!.id}`, { method: 'DELETE' })
  groupDelete.value = null
  await load()
}

onMounted(load)
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div class="space-y-0.5">
        <h2 class="text-xl font-semibold tracking-tight">Проекты</h2>
        <p class="text-sm text-muted-foreground">Группы проектов, а в проектах — серверы и базы данных</p>
      </div>
      <div class="flex gap-2">
        <button :class="btnClass.outline" @click="(groupEdit = { group: null, name: '' }), (groupError = '')"><Icon name="plus" />Новая группа</button>
        <button :class="btnClass.primary" @click="createFor = { groupId: null }"><Icon name="plus" />Новый проект</button>
      </div>
    </header>

    <input v-model="search" type="search" placeholder="Поиск по названию или описанию" :class="inputClass" class="max-w-sm" />
    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>

    <section v-for="g in sections" :key="g.group?.id ?? 'none'" class="grid gap-3">
      <div v-if="g.group || store.groups.length" class="flex flex-wrap items-center gap-2">
        <h3 class="font-semibold">{{ g.group?.name ?? 'Без группы' }}</h3>
        <span class="text-sm text-muted-foreground">{{ g.projects.length }}</span>
        <template v-if="g.group">
          <button class="ml-2 rounded p-1 text-muted-foreground hover:bg-accent hover:text-foreground" title="Переименовать группу" @click="(groupEdit = { group: g.group, name: g.group.name }), (groupError = '')"><Icon name="pencil" class="size-4" /></button>
          <button class="rounded p-1 text-muted-foreground hover:bg-accent hover:text-destructive" title="Удалить группу" @click="groupDelete = g.group"><Icon name="trash" class="size-4" /></button>
          <button class="ml-auto text-sm text-muted-foreground hover:text-foreground" @click="createFor = { groupId: g.group.id }">+ проект в группу</button>
        </template>
      </div>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <RouterLink v-for="p in g.projects" :key="p.id" :to="{ name: 'project', params: { id: p.id } }" class="grid gap-3 rounded-xl border bg-card p-5 shadow-xs hover:border-primary/50">
          <div class="space-y-0.5">
            <h4 class="font-semibold">{{ p.name }}</h4>
            <p class="truncate text-sm text-muted-foreground">{{ p.description || ' ' }}</p>
          </div>
          <div class="flex gap-4 text-sm text-muted-foreground">
            <span class="inline-flex items-center gap-1.5"><Icon name="server" class="size-4" />{{ p.servers_count }}</span>
            <span class="inline-flex items-center gap-1.5"><Icon name="database" class="size-4" />{{ p.databases_count }}</span>
          </div>
        </RouterLink>
        <p v-if="g.group && !g.projects.length" class="rounded-xl border border-dashed p-5 text-sm text-muted-foreground">В группе пока нет проектов</p>
      </div>
    </section>
    <p v-if="store.loaded && !store.list.length && !store.groups.length" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
      Проектов пока нет — создайте первый и добавьте в него серверы и базы данных
    </p>
    <p v-else-if="search && !sections.some((s) => s.projects.length)" class="text-sm text-muted-foreground">Ничего не нашли</p>

    <ProjectFormModal v-if="createFor" :project="null" :group-id="createFor.groupId" @close="closeCreate" @saved="onCreated" />

    <Modal v-if="groupEdit" :title="groupEdit.group ? 'Переименовать группу' : 'Новая группа'" @close="groupEdit = null">
      <form class="grid gap-4" @submit.prevent="saveGroup">
        <input v-model="groupEdit.name" required placeholder="Клиенты" :class="inputClass" />
        <p v-if="groupError" class="text-sm text-destructive">{{ groupError }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" :class="btnClass.outline" @click="groupEdit = null">Отмена</button>
          <button type="submit" :class="btnClass.primary">{{ groupEdit.group ? 'Сохранить' : 'Создать' }}</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="groupDelete" title="Удалить группу?" @close="groupDelete = null">
      <p class="text-sm text-muted-foreground">Группа «{{ groupDelete.name }}» будет удалена, её проекты останутся — без группы.</p>
      <div class="mt-6 flex justify-end gap-2">
        <button :class="btnClass.outline" @click="groupDelete = null">Отмена</button>
        <button :class="btnClass.destructive" @click="deleteGroup">Удалить</button>
      </div>
    </Modal>
  </div>
</template>
