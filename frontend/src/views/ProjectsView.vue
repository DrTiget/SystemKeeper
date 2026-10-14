<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client'
import type { Project } from '@/api/projects'
import Icon from '@/components/Icon.vue'
import ProjectFormModal from '@/components/ProjectFormModal.vue'
import { btnClass, inputClass } from '@/lib/ui'
import { useProjectsStore } from '@/stores/projects'

const router = useRouter()
const route = useRoute()
const store = useProjectsStore()
const projects = ref<Project[]>([])
const search = ref('')
const loading = ref(true)
const loadError = ref('')

async function load() {
  loading.value = true
  try {
    const qs = new URLSearchParams({ per_page: '100' })
    if (search.value.trim()) qs.set('search', search.value.trim())
    projects.value = (await api<{ data: Project[] }>(`/projects?${qs}`)).data
  } catch {
    loadError.value = 'Не удалось загрузить проекты'
  } finally {
    loading.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(load, 300)
})

// «+» в меню открывает создание: /projects?new=1
const createOpen = ref(!!route.query.new)
watch(() => route.query.new, (v) => v && (createOpen.value = true))
function closeCreate() {
  createOpen.value = false
  if (route.query.new) router.replace({ query: {} })
}
async function onCreated(p: Project) {
  await store.load()
  router.push({ name: 'project', params: { id: p.id } })
}

onMounted(load)
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div class="space-y-0.5">
        <h2 class="text-xl font-semibold tracking-tight">Проекты</h2>
        <p class="text-sm text-muted-foreground">Группы серверов и баз данных</p>
      </div>
      <button :class="btnClass.primary" @click="createOpen = true"><Icon name="plus" />Новый проект</button>
    </header>

    <input v-model="search" type="search" placeholder="Поиск по названию или описанию" :class="inputClass" class="max-w-sm" />
    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <RouterLink v-for="p in projects" :key="p.id" :to="{ name: 'project', params: { id: p.id } }" class="grid gap-3 rounded-xl border bg-card p-5 shadow-xs hover:border-primary/50">
        <div class="space-y-0.5">
          <h3 class="font-semibold">{{ p.name }}</h3>
          <p class="truncate text-sm text-muted-foreground">{{ p.description || ' ' }}</p>
        </div>
        <div class="flex gap-4 text-sm text-muted-foreground">
          <span class="inline-flex items-center gap-1.5"><Icon name="server" class="size-4" />{{ p.servers_count }}</span>
          <span class="inline-flex items-center gap-1.5"><Icon name="database" class="size-4" />{{ p.databases_count }}</span>
        </div>
      </RouterLink>
    </div>
    <p v-if="!loading && !projects.length" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
      {{ search ? 'Ничего не нашли' : 'Проектов пока нет — создайте первый и добавьте в него серверы и базы данных' }}
    </p>

    <ProjectFormModal v-if="createOpen" :project="null" @close="closeCreate" @saved="onCreated" />
  </div>
</template>
