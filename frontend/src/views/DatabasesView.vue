<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { type DatabaseConnection, ENGINE_LABELS } from '@/api/databases'
import Icon from '@/components/Icon.vue'
import ProjectSelect from '@/components/ProjectSelect.vue'
import DatabaseFormModal from '@/components/databases/DatabaseFormModal.vue'
import DatabasesTable from '@/components/databases/DatabasesTable.vue'
import { describeCron } from '@/lib/cron'
import { btnClass, formatDate, inputClass } from '@/lib/ui'

interface Page {
  data: DatabaseConnection[]
  meta: { current_page: number; last_page: number; total: number }
}

const router = useRouter()

const items = ref<DatabaseConnection[]>([])
const meta = ref<Page['meta'] | null>(null)
const page = ref(1)
const search = ref('')
const projectFilter = ref<number | null>(null)
const loading = ref(false)
const loadError = ref('')

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const qs = new URLSearchParams({ page: String(page.value) })
    if (search.value.trim()) qs.set('search', search.value.trim())
    if (projectFilter.value) qs.set('project_id', String(projectFilter.value))
    const res = await api<Page>(`/databases?${qs}`)
    items.value = res.data
    meta.value = res.meta
  } catch {
    loadError.value = 'Не удалось загрузить базы данных'
  } finally {
    loading.value = false
  }
}

watch(projectFilter, () => {
  page.value = 1
  load()
})

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    load()
  }, 300)
})

function goTo(p: number) {
  page.value = p
  load()
}

const createOpen = ref(false)

function onCreated(c: DatabaseConnection, provision: boolean) {
  createOpen.value = false
  router.push({ name: 'database', params: { id: c.id }, query: provision ? { provision: '1' } : { check: '1' } })
}

const whatDumped = (c: DatabaseConnection) =>
  c.backup.databases.length ? `${c.backup.databases.length} ${c.backup.databases.length === 1 ? 'база' : c.backup.databases.length < 5 ? 'базы' : 'баз'}` : 'все базы'

onMounted(load)
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div class="space-y-0.5">
        <h2 class="text-xl font-semibold tracking-tight">Базы данных</h2>
        <p class="text-sm text-muted-foreground">Подключения к MySQL и PostgreSQL и что из них дампить</p>
      </div>
      <button :class="btnClass.primary" @click="createOpen = true"><Icon name="plus" />Добавить</button>
    </header>

    <div class="flex flex-wrap gap-2">
      <input v-model="search" type="search" placeholder="Поиск по названию, хосту или описанию" :class="inputClass" class="max-w-sm flex-1" />
      <div class="w-56"><ProjectSelect v-model="projectFilter" none-label="Все проекты" /></div>
    </div>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>

    <DatabasesTable :items="items" :empty-text="loading ? 'Загрузка…' : search ? 'Ничего не нашли' : 'Баз данных пока нет — добавьте первую'" />

    <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between gap-3 text-sm text-muted-foreground">
      <span>Всего: {{ meta.total }}</span>
      <div class="flex items-center gap-2">
        <button :class="btnClass.outline" :disabled="meta.current_page <= 1" @click="goTo(meta.current_page - 1)">Назад</button>
        <span>{{ meta.current_page }} / {{ meta.last_page }}</span>
        <button :class="btnClass.outline" :disabled="meta.current_page >= meta.last_page" @click="goTo(meta.current_page + 1)">Вперёд</button>
      </div>
    </div>

    <DatabaseFormModal v-if="createOpen" :connection="null" @close="createOpen = false" @saved="onCreated" />
  </div>
</template>
