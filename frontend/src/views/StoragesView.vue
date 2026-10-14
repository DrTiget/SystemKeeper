<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { type BackupStorage, type Retention, type StorageSource, STORAGE_TYPE_LABELS } from '@/api/storages'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import RetentionFields from '@/components/RetentionFields.vue'
import S3StorageModal from '@/components/S3StorageModal.vue'
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
    loadError.value = 'Не удалось загрузить список'
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

/** Источники по группам проектов → проектам; без группы и без проекта — в конце. */
function grouped(sources: StorageSource[]) {
  const groups = new Map<string, { name: string | null; projects: Map<string, { name: string | null; id: number | null; rows: StorageSource[] }> }>()
  for (const src of sources) {
    const gKey = src.project?.group ? `g${src.project.group.id}` : 'g-'
    if (!groups.has(gKey)) groups.set(gKey, { name: src.project?.group?.name ?? null, projects: new Map() })
    const projects = groups.get(gKey)!.projects
    const pKey = src.project ? `p${src.project.id}` : 'p-'
    if (!projects.has(pKey)) projects.set(pKey, { name: src.project?.name ?? null, id: src.project?.id ?? null, rows: [] })
    projects.get(pKey)!.rows.push(src)
  }
  const byName = <T extends { name: string | null }>(a: T, b: T) => (a.name === null ? 1 : b.name === null ? -1 : a.name.localeCompare(b.name, 'ru'))
  return [...groups.values()].sort(byName).map((g) => ({ ...g, projects: [...g.projects.values()].sort(byName) }))
}

// S3: добавление, изменение, проверка, удаление
const s3Edit = ref<{ storage: BackupStorage | null } | null>(null)
const checks = ref<Record<number, { status: 'checking' | 'ok' | 'failed'; error?: string | null }>>({})
const deleting = ref<BackupStorage | null>(null)
const deleteError = ref('')

async function check(st: BackupStorage) {
  checks.value[st.id] = { status: 'checking' }
  try {
    checks.value[st.id] = (await api<{ data: { status: 'ok' | 'failed'; error: string | null } }>(`/storages/${st.id}/check`, { method: 'POST' })).data
  } catch (e) {
    checks.value[st.id] = { status: 'failed', error: e instanceof ApiError ? e.message : 'Не удалось проверить' }
  }
}

async function onS3Saved(st: BackupStorage) {
  s3Edit.value = null
  await load()
  check(st)
}

async function destroy() {
  deleteError.value = ''
  try {
    await api(`/storages/${deleting.value!.id}`, { method: 'DELETE' })
    deleting.value = null
    await load()
  } catch (e) {
    deleteError.value = e instanceof ApiError ? e.message : 'Не удалось удалить'
  }
}

const sourceLink = (s: StorageSource) => ({ name: s.type === 'server' ? 'server' : 'database', params: { id: s.id } })

onMounted(load)

const card = 'rounded-xl border bg-card p-6 shadow-xs'
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div class="space-y-0.5">
        <h2 class="text-xl font-semibold tracking-tight">Диски и облака</h2>
        <p class="text-sm text-muted-foreground">Где лежат бекапы, сколько места занято и сколько копий хранится у каждого сервера и базы</p>
      </div>
      <button :class="btnClass.primary" @click="s3Edit = { storage: null }"><Icon name="plus" />Подключить S3</button>
    </header>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>
    <p v-else-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>

    <section v-for="st in storages" :key="st.id" :class="card" class="grid gap-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="space-y-0.5">
          <h3 class="flex items-center gap-2 font-semibold"><Icon name="archive" class="size-4" />{{ st.name }}</h3>
          <p class="text-sm text-muted-foreground">
            {{ STORAGE_TYPE_LABELS[st.type] }}<template v-if="st.is_default"> · по умолчанию</template>
            <template v-if="st.s3">
              · <span class="font-mono text-xs">{{ st.s3.bucket }}{{ st.s3.prefix ? '/' + st.s3.prefix : '' }}</span>
              · {{ st.s3.endpoint ? st.s3.endpoint.replace(/^https?:\/\//, '') : 'Amazon S3' }}
            </template>
          </p>
        </div>
        <div class="flex items-start gap-4">
          <div class="text-right text-sm">
            <div class="font-medium">{{ formatBytes(st.used) }}</div>
            <div class="text-muted-foreground">в {{ st.backups_count }} бекапах</div>
          </div>
          <div v-if="st.type === 's3'" class="flex gap-2">
            <button :class="btnClass.outline" :disabled="checks[st.id]?.status === 'checking'" @click="check(st)">
              <Icon :name="checks[st.id]?.status === 'checking' ? 'loader' : 'plug'" :class="checks[st.id]?.status === 'checking' && 'animate-spin'" />Проверить
            </button>
            <button :class="btnClass.outline" class="size-9! p-0!" title="Изменить" @click="s3Edit = { storage: st }"><Icon name="pencil" class="size-4" /></button>
            <button :class="btnClass.destructive" class="size-9! p-0!" title="Удалить" @click="(deleting = st), (deleteError = '')"><Icon name="trash" class="size-4" /></button>
          </div>
        </div>
      </div>
      <p v-if="checks[st.id]?.status === 'ok'" class="-mt-2 flex items-center gap-2 text-sm"><span class="size-2 rounded-full bg-emerald-500" />Подключение работает: файл записан, прочитан и удалён</p>
      <p v-else-if="checks[st.id]?.status === 'failed'" class="-mt-2 flex items-center gap-2 text-sm text-destructive"><span class="size-2 rounded-full bg-destructive" />{{ checks[st.id]?.error }}</p>

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
          <tbody v-for="g in grouped(st.sources ?? [])" :key="g.name ?? '-'" class="divide-y border-b last:border-b-0">
            <tr v-if="g.name" class="bg-muted/40">
              <td colspan="4" class="px-4 py-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">{{ g.name }}</td>
            </tr>
            <template v-for="p in g.projects" :key="p.id ?? '-'">
              <tr>
                <td colspan="4" class="px-4 pt-3 pb-1.5">
                  <RouterLink v-if="p.id" :to="`/projects/${p.id}`" class="inline-flex items-center gap-2 font-medium hover:underline"><Icon name="folder" class="size-4 text-muted-foreground" />{{ p.name }}</RouterLink>
                  <span v-else class="inline-flex items-center gap-2 text-muted-foreground"><Icon name="folder" class="size-4" />Без проекта</span>
                </td>
              </tr>
              <tr v-for="s in p.rows" :key="`${s.type}${s.id}`" class="border-t-0!">
                <td class="py-2 pr-4 pl-10">
                  <RouterLink :to="sourceLink(s)" class="inline-flex items-center gap-2 hover:underline">
                    <Icon :name="s.type === 'server' ? 'server' : 'database'" class="size-4 text-muted-foreground" />{{ s.name }}
                  </RouterLink>
                </td>
                <td class="px-4 py-2 whitespace-nowrap">{{ s.backups_count }} <span class="text-muted-foreground">из {{ s.keep_count }}</span></td>
                <td class="px-4 py-2">
                  <div class="whitespace-nowrap">{{ formatBytes(s.used) }} <span class="text-muted-foreground">/ {{ s.max_size ? formatBytes(s.max_size) : 'без лимита' }}</span></div>
                  <div v-if="s.max_size" class="mt-1 h-1.5 w-40 overflow-hidden rounded-full bg-muted">
                    <div class="h-full" :class="s.used > s.max_size * 0.9 ? 'bg-destructive' : 'bg-primary'" :style="{ width: pct(s.used, s.max_size) }" />
                  </div>
                </td>
                <td class="px-2"><button :class="btnClass.outline" class="size-8! p-0!" title="Сколько хранить" @click="edit(s, st)"><Icon name="pencil" class="size-4" /></button></td>
              </tr>
            </template>
          </tbody>
          <tbody v-if="!st.sources?.length">
            <tr>
              <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">Сюда пока ничего не бекапится{{ st.type === 's3' ? ' — выберите это место в настройках бекапа сервера или базы' : '' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <S3StorageModal v-if="s3Edit" :storage="s3Edit.storage" @close="s3Edit = null" @saved="onS3Saved" />

    <Modal v-if="deleting" title="Удалить хранилище?" @close="deleting = null">
      <p class="text-sm text-muted-foreground">«{{ deleting.name }}» будет отключено от SystemKeeper. Файлы в бакете не удаляются.</p>
      <p v-if="deleteError" class="mt-3 text-sm text-destructive">{{ deleteError }}</p>
      <div class="mt-6 flex justify-end gap-2">
        <button :class="btnClass.outline" @click="deleting = null">Отмена</button>
        <button :class="btnClass.destructive" @click="destroy">Удалить</button>
      </div>
    </Modal>

    <Modal v-if="editing" wide :title="`Сколько хранить: ${editing.name}`" @close="editing = null">
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
