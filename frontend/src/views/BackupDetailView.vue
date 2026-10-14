<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import { BACKUP_STATUS, type BackupDetail, backupDuration, backupTrigger, downloadBackupFile, isActive } from '@/api/backups'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { useProjectsStore } from '@/stores/projects'
import { btnClass, formatBytes, formatDate } from '@/lib/ui'

const route = useRoute()
const router = useRouter()
const id = Number(route.params.id)

const backup = ref<BackupDetail | null>(null)
const loadError = ref('')
const error = ref('')

const sourceLink = computed(() => backup.value && { name: backup.value.source_type === 'server' ? 'server' : 'database', params: { id: backup.value.source_id } })
const sourceName = computed(() => backup.value?.source?.name ?? (backup.value?.source_type === 'server' ? 'Сервер' : 'База данных'))
const title = computed(() => backup.value && `Бекап от ${formatDate(backup.value.started_at ?? backup.value.created_at)}`)

const projects = useProjectsStore()
watch(backup, (b) => {
  if (!b) return
  const project = b.source?.project
  projects.crumbs = [
    ...(project ? [{ label: project.name, to: `/projects/${project.id}` }] : []),
    { label: sourceName.value, to: router.resolve(sourceLink.value!).href },
    { label: title.value! },
  ]
})

let timer: ReturnType<typeof setTimeout> | undefined
async function load() {
  clearTimeout(timer)
  try {
    backup.value = (await api<{ data: BackupDetail }>(`/backups/${id}`)).data
  } catch (e) {
    loadError.value = e instanceof ApiError && e.status === 404 ? 'Бекап не найден' : 'Не удалось загрузить бекап'
    return
  }
  // пока бекап идёт — обновляем, чтобы был виден журнал
  if (isActive(backup.value)) timer = setTimeout(load, 2000)
}

async function download(name: string) {
  error.value = ''
  try {
    await downloadBackupFile(backup.value!, name)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Не удалось скачать'
  }
}

const deleteOpen = ref(false)
const deleteError = ref('')

async function destroy() {
  deleteError.value = ''
  try {
    await api(`/backups/${id}`, { method: 'DELETE' })
    router.push(sourceLink.value!)
  } catch (e) {
    deleteError.value = e instanceof ApiError ? e.message : 'Не удалось удалить'
  }
}

onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))

const card = 'rounded-xl border bg-card p-6 shadow-xs'
const dt = 'text-xs text-muted-foreground'
</script>

<template>
  <div class="flex max-w-7xl flex-col gap-6 p-4">
    <RouterLink v-if="sourceLink" :to="sourceLink" class="inline-flex items-center gap-1.5 self-start text-sm text-muted-foreground hover:text-foreground">
      <Icon name="arrow-left" class="size-4" />{{ sourceName }}
    </RouterLink>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>
    <p v-else-if="!backup" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>

    <template v-else>
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="space-y-1">
          <h2 class="flex items-center gap-2 text-xl font-semibold tracking-tight"><Icon name="archive" class="size-5 text-muted-foreground" />{{ title }}</h2>
          <p class="flex items-center gap-1.5 text-sm" :class="backup.status === 'failed' ? 'text-destructive' : 'text-muted-foreground'">
            <span class="size-2 rounded-full" :class="BACKUP_STATUS[backup.status].dot" />{{ BACKUP_STATUS[backup.status].label }}
            <span class="text-muted-foreground">· {{ backupTrigger(backup) }}</span>
          </p>
        </div>
        <button v-if="!isActive(backup)" :class="btnClass.destructive" @click="(deleteOpen = true), (deleteError = '')"><Icon name="trash" />Удалить бекап</button>
      </header>

      <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="grid gap-6">
          <section v-if="backup.error" class="rounded-xl border border-destructive/40 bg-destructive/5 p-4">
            <h3 class="mb-1 text-sm font-semibold text-destructive">Ошибка</h3>
            <p class="text-sm whitespace-pre-wrap text-destructive">{{ backup.error }}</p>
          </section>

          <section :class="card" class="grid gap-4">
            <div class="flex items-baseline justify-between gap-3">
              <h3 class="font-semibold">Файлы</h3>
              <span v-if="backup.size !== null" class="text-sm text-muted-foreground">всего {{ formatBytes(backup.size) }}</span>
            </div>
            <ul v-if="backup.files.length" class="divide-y rounded-lg border text-sm">
              <li v-for="f in backup.files" :key="f.name" class="flex items-center gap-3 py-2 pr-2 pl-3">
                <Icon name="file" class="size-4 shrink-0 text-muted-foreground" />
                <span class="min-w-0 flex-1 truncate font-mono text-xs">{{ f.name }}</span>
                <span class="text-xs whitespace-nowrap text-muted-foreground">{{ formatBytes(f.size) }}</span>
                <button :class="btnClass.outline" class="h-8! px-3!" @click="download(f.name)"><Icon name="download" />Скачать</button>
              </li>
            </ul>
            <p v-else class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
              {{ isActive(backup) ? 'Файлы появятся, когда бекап завершится' : 'Файлов нет' }}
            </p>
            <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
          </section>

          <section :class="card" class="grid gap-4">
            <h3 class="flex items-center gap-2 font-semibold">
              Журнал
              <Icon v-if="isActive(backup)" name="loader" class="size-4 animate-spin text-muted-foreground" />
            </h3>
            <ol v-if="backup.log.length" class="grid gap-1 rounded-lg border bg-muted/30 p-3 font-mono text-xs">
              <li v-for="(l, i) in backup.log" :key="i" class="flex gap-3">
                <span class="shrink-0 text-muted-foreground">{{ new Date(l.at).toLocaleTimeString('ru-RU') }}</span>
                <span class="min-w-0 break-words whitespace-pre-wrap">{{ l.message }}</span>
              </li>
            </ol>
            <p v-else class="text-sm text-muted-foreground">Записей нет</p>
          </section>
        </div>

        <aside :class="card" class="p-4!">
          <h3 class="mb-4 font-semibold">Сведения</h3>
          <dl class="grid gap-3 text-sm">
            <div class="grid gap-0.5">
              <dt :class="dt">{{ backup.source_type === 'server' ? 'Сервер' : 'База данных' }}</dt>
              <dd>
                <RouterLink v-if="sourceLink" :to="sourceLink" class="inline-flex items-center gap-1.5 underline-offset-2 hover:underline">
                  <Icon :name="backup.source_type === 'server' ? 'server' : 'database'" class="size-4 text-muted-foreground" />{{ sourceName }}
                </RouterLink>
              </dd>
            </div>
            <div class="grid gap-0.5">
              <dt :class="dt">Запуск</dt>
              <dd>{{ backup.trigger === 'manual' ? 'Вручную' : 'По расписанию' }}<template v-if="backup.user">, {{ backup.user.name }}</template></dd>
            </div>
            <div class="grid gap-0.5">
              <dt :class="dt">Поставлен в очередь</dt>
              <dd>{{ formatDate(backup.created_at) }}</dd>
            </div>
            <div v-if="backup.started_at" class="grid gap-0.5">
              <dt :class="dt">Начат</dt>
              <dd>{{ formatDate(backup.started_at) }}</dd>
            </div>
            <div v-if="backup.finished_at" class="grid gap-0.5">
              <dt :class="dt">Завершён</dt>
              <dd>{{ formatDate(backup.finished_at) }}</dd>
            </div>
            <div v-if="backup.started_at" class="grid gap-0.5">
              <dt :class="dt">Длительность</dt>
              <dd>{{ backupDuration(backup) }}</dd>
            </div>
            <div v-if="backup.copies.length" class="grid gap-1">
              <dt :class="dt">Где лежит</dt>
              <dd v-for="c in backup.copies" :key="c.storage_id" class="flex items-start gap-1.5" :title="c.error ?? ''">
                <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="c.status === 'success' ? 'bg-emerald-500' : 'bg-destructive'" />
                <span class="min-w-0">
                  {{ c.name ?? 'удалённое место' }}
                  <span v-if="c.status === 'failed'" class="block text-xs break-words text-destructive">{{ c.error }}</span>
                </span>
              </dd>
            </div>
            <div class="grid gap-0.5">
              <dt :class="dt">Размер</dt>
              <dd>{{ backup.size !== null ? formatBytes(backup.size) : '—' }}<span v-if="backup.files.length" class="text-muted-foreground"> · файлов: {{ backup.files.length }}</span></dd>
            </div>
          </dl>
        </aside>
      </div>
    </template>

    <Modal v-if="deleteOpen && backup" title="Удалить бекап?" @close="deleteOpen = false">
      <p class="text-sm text-muted-foreground">{{ title }} ({{ sourceName }}) будет удалён вместе с файлами. Отменить это нельзя.</p>
      <p v-if="deleteError" class="mt-3 text-sm text-destructive">{{ deleteError }}</p>
      <div class="mt-6 flex justify-end gap-2">
        <button :class="btnClass.outline" @click="deleteOpen = false">Отмена</button>
        <button :class="btnClass.destructive" @click="destroy">Удалить</button>
      </div>
    </Modal>
  </div>
</template>
