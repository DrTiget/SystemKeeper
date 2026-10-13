<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import type { Server } from '@/api/servers'
import Icon from '@/components/Icon.vue'
import ServerFormModal from '@/components/servers/ServerFormModal.vue'
import { describeCron } from '@/lib/cron'
import { btnClass, formatDate, inputClass } from '@/lib/ui'

interface Page {
  data: Server[]
  meta: { current_page: number; last_page: number; total: number }
}

const router = useRouter()

const servers = ref<Server[]>([])
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
    const res = await api<Page>(`/servers?${qs}`)
    servers.value = res.data
    meta.value = res.meta
  } catch {
    loadError.value = 'Не удалось загрузить серверы'
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

function openServer(s: Server) {
  router.push({ name: 'server', params: { id: s.id } })
}

// Новый сервер: после сохранения — на его страницу (со сгенерированным ключом сразу показываем открытый ключ)
const createOpen = ref(false)

function onCreated(server: Server, generatedKey: boolean) {
  createOpen.value = false
  router.push({ name: 'server', params: { id: server.id }, query: generatedKey ? { key: '1' } : { check: '1' } })
}

onMounted(load)

const pluralDirs = (n: number) => (n % 10 === 1 && n % 100 !== 11 ? 'путь' : [2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? 'пути' : 'путей')
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div class="space-y-0.5">
        <h2 class="text-xl font-semibold tracking-tight">Сервера</h2>
        <p class="text-sm text-muted-foreground">Нажмите на сервер, чтобы открыть подключение и настройки бекапа</p>
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
            <th class="px-4 py-3 font-medium">SSH</th>
            <th class="px-4 py-3 font-medium">Бекап</th>
            <th class="w-8 px-2" />
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="s in servers" :key="s.id" class="cursor-pointer hover:bg-accent/40" @click="openServer(s)">
            <td class="px-4 py-3">
              <RouterLink :to="{ name: 'server', params: { id: s.id } }" class="font-medium hover:underline" @click.stop>{{ s.name }}</RouterLink>
              <div v-if="s.description" class="max-w-xs truncate text-xs text-muted-foreground">{{ s.description }}</div>
            </td>
            <td class="px-4 py-3 whitespace-nowrap">
              <div class="font-mono text-xs">{{ s.username }}@{{ s.host }}{{ s.port === 22 ? '' : `:${s.port}` }}</div>
              <div class="text-xs text-muted-foreground">{{ s.auth_type === 'key' ? 'по ключу' : 'по паролю' }}</div>
            </td>
            <td class="px-4 py-3">
              <span v-if="s.last_check.status === 'ok'" class="inline-flex items-center gap-1.5" :title="`Проверено ${formatDate(s.last_check.checked_at)}`">
                <span class="size-2 rounded-full bg-emerald-500" />Работает
              </span>
              <span v-else-if="s.last_check.status === 'failed'" class="inline-flex max-w-56 items-center gap-1.5 text-destructive" :title="s.last_check.error ?? ''">
                <span class="size-2 shrink-0 rounded-full bg-destructive" /><span class="truncate">{{ s.last_check.error }}</span>
              </span>
              <span v-else class="text-muted-foreground">Не проверялся</span>
            </td>
            <td class="px-4 py-3">
              <template v-if="s.backup?.enabled && s.backup.schedule">
                <div class="whitespace-nowrap">{{ describeCron(s.backup.schedule) }}</div>
                <div class="text-xs text-muted-foreground">
                  {{ s.backup.paths_count }} {{ pluralDirs(s.backup.paths_count) }}<template v-if="s.backup.next_run_at"> · след. {{ formatDate(s.backup.next_run_at) }}</template>
                </div>
              </template>
              <span v-else class="text-muted-foreground">Не настроен</span>
            </td>
            <td class="px-2 text-muted-foreground"><Icon name="chevron-right" class="size-4" /></td>
          </tr>
          <tr v-if="!servers.length && !loading">
            <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">{{ search ? 'Ничего не нашли' : 'Серверов пока нет — добавьте первый' }}</td>
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

    <ServerFormModal v-if="createOpen" :server="null" @close="createOpen = false" @saved="onCreated" />
  </div>
</template>
