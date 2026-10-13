<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { type DatabaseConnection, ENGINE_LABELS } from '@/api/databases'
import Icon from '@/components/Icon.vue'
import DatabaseFormModal from '@/components/databases/DatabaseFormModal.vue'
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
const loading = ref(false)
const loadError = ref('')

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const qs = new URLSearchParams({ page: String(page.value) })
    if (search.value.trim()) qs.set('search', search.value.trim())
    const res = await api<Page>(`/databases?${qs}`)
    items.value = res.data
    meta.value = res.meta
  } catch {
    loadError.value = 'Не удалось загрузить базы данных'
  } finally {
    loading.value = false
  }
}

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

function onCreated(c: DatabaseConnection) {
  createOpen.value = false
  router.push({ name: 'database', params: { id: c.id }, query: { check: '1' } })
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

    <input v-model="search" type="search" placeholder="Поиск по названию, хосту или описанию" :class="inputClass" class="max-w-sm" />

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>

    <div class="overflow-x-auto rounded-xl border bg-card">
      <table class="w-full text-sm">
        <thead class="border-b text-left text-muted-foreground">
          <tr>
            <th class="px-4 py-3 font-medium">Название</th>
            <th class="px-4 py-3 font-medium">Подключение</th>
            <th class="px-4 py-3 font-medium">Состояние</th>
            <th class="px-4 py-3 font-medium">Бекап</th>
            <th class="w-8 px-2" />
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="c in items" :key="c.id" class="cursor-pointer hover:bg-accent/40" @click="router.push({ name: 'database', params: { id: c.id } })">
            <td class="px-4 py-3">
              <RouterLink :to="{ name: 'database', params: { id: c.id } }" class="font-medium hover:underline" @click.stop>{{ c.name }}</RouterLink>
              <div class="text-xs text-muted-foreground">{{ ENGINE_LABELS[c.engine] }}<template v-if="c.last_check.server_version"> · {{ c.last_check.server_version.split(' ').slice(0, 2).join(' ') }}</template></div>
            </td>
            <td class="px-4 py-3 whitespace-nowrap">
              <div class="font-mono text-xs">{{ c.username }}@{{ c.host }}:{{ c.port }}</div>
              <div class="text-xs text-muted-foreground">
                <template v-if="c.via === 'ssh'">через {{ c.server?.name }}<template v-if="c.docker_container">, контейнер {{ c.docker_container }}</template></template>
                <template v-else>напрямую</template>
              </div>
            </td>
            <td class="px-4 py-3">
              <span v-if="c.last_check.status === 'ok'" class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-emerald-500" />Работает</span>
              <span v-else-if="c.last_check.status === 'failed'" class="inline-flex max-w-56 items-center gap-1.5 text-destructive" :title="c.last_check.error ?? ''">
                <span class="size-2 shrink-0 rounded-full bg-destructive" /><span class="truncate">{{ c.last_check.error }}</span>
              </span>
              <span v-else class="text-muted-foreground">Не проверялась</span>
            </td>
            <td class="px-4 py-3">
              <template v-if="c.backup.enabled">
                <div class="whitespace-nowrap">{{ describeCron(c.backup.schedule) }}</div>
                <div class="text-xs text-muted-foreground">{{ whatDumped(c) }}<template v-if="c.backup.next_run_at"> · след. {{ formatDate(c.backup.next_run_at) }}</template></div>
              </template>
              <span v-else class="text-muted-foreground">Не настроен</span>
            </td>
            <td class="px-2 text-muted-foreground"><Icon name="chevron-right" class="size-4" /></td>
          </tr>
          <tr v-if="!items.length && !loading">
            <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">{{ search ? 'Ничего не нашли' : 'Баз данных пока нет — добавьте первую' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

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
