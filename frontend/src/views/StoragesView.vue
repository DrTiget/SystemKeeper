<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { type BackupStorage, type Retention, type StorageSource, STORAGE_TYPE_LABELS } from '@/api/storages'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import RetentionFields from '@/components/RetentionFields.vue'
import { btnClass, formatBytes } from '@/lib/ui'

const storages = ref<BackupStorage[]>([])
const loading = ref(true)
const loadError = ref('')

async function load() {
  try {
    const list = (await api<{ data: BackupStorage[] }>('/storages')).data
    // источники — из карточки каждого хранилища
    storages.value = await Promise.all(list.map(async (s) => (await api<{ data: BackupStorage }>(`/storages/${s.id}`)).data))
  } catch {
    loadError.value = 'Не удалось загрузить хранилища'
  } finally {
    loading.value = false
  }
}

const pct = (part: number, total: number) => `${Math.min(100, Math.max(0, (part / total) * 100))}%`

// Настройки хранения источника
const editing = ref<StorageSource | null>(null)
const retention = ref<Retention>({ storage_id: null, keep_count: 7, max_size: null })
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const saveError = ref('')

function edit(s: StorageSource, storage: BackupStorage) {
  editing.value = s
  retention.value = { storage_id: storage.id, keep_count: s.keep_count, max_size: s.max_size }
  errors.value = {}
  saveError.value = ''
}

async function save() {
  const s = editing.value!
  saving.value = true
  errors.value = {}
  saveError.value = ''
  try {
    if (s.type === 'server') await api(`/servers/${s.id}/backup-settings`, { method: 'PUT', body: retention.value })
    else await api(`/databases/${s.id}`, { method: 'PATCH', body: retention.value })
    editing.value = null
    await load()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) errors.value = e.errors
    else saveError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

const sourceLink = (s: StorageSource) => ({ name: s.type === 'server' ? 'server' : 'database', params: { id: s.id } })

onMounted(load)

const card = 'rounded-xl border bg-card p-6 shadow-xs'
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="space-y-0.5">
      <h2 class="text-xl font-semibold tracking-tight">Хранилища</h2>
      <p class="text-sm text-muted-foreground">Где лежат бекапы, сколько места занято и сколько копий хранится у каждого сервера и базы</p>
    </header>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>
    <p v-else-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>

    <section v-for="st in storages" :key="st.id" :class="card" class="grid gap-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="space-y-0.5">
          <h3 class="flex items-center gap-2 font-semibold"><Icon name="archive" class="size-4" />{{ st.name }}</h3>
          <p class="text-sm text-muted-foreground">{{ STORAGE_TYPE_LABELS[st.type] }}<template v-if="st.is_default"> · по умолчанию</template></p>
        </div>
        <div class="text-right text-sm">
          <div class="font-medium">{{ formatBytes(st.used) }}</div>
          <div class="text-muted-foreground">в {{ st.backups_count }} бекапах</div>
        </div>
      </div>

      <div v-if="st.disk" class="grid gap-2.5">
        <div class="flex items-baseline justify-between gap-3 text-sm">
          <span class="font-medium">Диск занят на {{ Math.round(((st.disk.total - st.disk.free) / st.disk.total) * 100) }}%</span>
          <span :class="st.disk.free / st.disk.total < 0.1 ? 'text-destructive' : 'text-muted-foreground'">свободно {{ formatBytes(st.disk.free) }}</span>
        </div>
        <!-- бекапы — всегда видимой полоской, даже если их доля меньше пикселя -->
        <div class="flex h-2.5 gap-px overflow-hidden rounded-full bg-foreground/10">
          <div v-if="st.used" class="min-w-1.5 bg-primary" :style="{ width: pct(st.used, st.disk.total) }" :title="`Бекапы: ${formatBytes(st.used)}`" />
          <div class="bg-foreground/35" :style="{ width: pct(st.disk.total - st.disk.free - st.used, st.disk.total) }" title="Занято другими файлами" />
        </div>
        <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm">
          <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-sm bg-primary" />Бекапы — {{ formatBytes(st.used) }}</span>
          <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-sm bg-foreground/35" />Другие файлы — {{ formatBytes(Math.max(0, st.disk.total - st.disk.free - st.used)) }}</span>
          <span class="inline-flex items-center gap-1.5"><span class="size-3 rounded-sm bg-foreground/10" />Свободно — {{ formatBytes(st.disk.free) }}</span>
          <span class="text-muted-foreground">всего {{ formatBytes(st.disk.total) }}</span>
        </div>
      </div>

      <div class="overflow-x-auto rounded-lg border">
        <table class="w-full text-sm">
          <thead class="border-b text-left text-muted-foreground">
            <tr>
              <th class="px-4 py-2.5 font-medium">Что бекапится</th>
              <th class="px-4 py-2.5 font-medium">Бекапов</th>
              <th class="px-4 py-2.5 font-medium">Занято / лимит</th>
              <th class="w-10" />
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="s in st.sources" :key="`${s.type}${s.id}`">
              <td class="px-4 py-2.5">
                <RouterLink :to="sourceLink(s)" class="inline-flex items-center gap-2 font-medium hover:underline">
                  <Icon :name="s.type === 'server' ? 'server' : 'database'" class="size-4 text-muted-foreground" />{{ s.name }}
                </RouterLink>
              </td>
              <td class="px-4 py-2.5 whitespace-nowrap">{{ s.backups_count }} <span class="text-muted-foreground">из {{ s.keep_count }}</span></td>
              <td class="px-4 py-2.5">
                <div class="whitespace-nowrap">{{ formatBytes(s.used) }} <span class="text-muted-foreground">/ {{ s.max_size ? formatBytes(s.max_size) : 'без лимита' }}</span></div>
                <div v-if="s.max_size" class="mt-1 h-1.5 w-40 overflow-hidden rounded-full bg-muted">
                  <div class="h-full" :class="s.used > s.max_size * 0.9 ? 'bg-destructive' : 'bg-primary'" :style="{ width: pct(s.used, s.max_size) }" />
                </div>
              </td>
              <td class="px-2"><button :class="btnClass.outline" class="size-8! p-0!" title="Настройки хранения" @click="edit(s, st)"><Icon name="pencil" class="size-4" /></button></td>
            </tr>
            <tr v-if="!st.sources?.length">
              <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">Сюда пока ничего не бекапится</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <Modal v-if="editing" wide :title="`Хранение: ${editing.name}`" @close="editing = null">
      <form class="grid gap-5" @submit.prevent="save">
        <RetentionFields v-model="retention" :errors="errors" />
        <p v-if="saveError" class="text-sm text-destructive">{{ saveError }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" :class="btnClass.outline" @click="editing = null">Отмена</button>
          <button type="submit" :disabled="saving" :class="btnClass.primary">Сохранить</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
