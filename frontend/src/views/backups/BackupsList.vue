<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import type { Backup, BackupJob, Paginated } from '@/api/types'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import CopyButton from '@/components/CopyButton.vue'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import Pagination from '@/components/Pagination.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { downloadBackup, isActive, statusBadge, typeLabel } from '@/lib/backup'
import { btnClass, errorMessage, formatBytes, formatDate, formatDuration, inputClass } from '@/lib/format'

const props = defineProps<{ jobId: number | null; jobs: BackupJob[] }>()
const emit = defineEmits<{ 'update:jobId': [id: number | null] }>()

const items = ref<Backup[]>([])
const meta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
const loading = ref(true)
const loadError = ref('')
const status = ref('')

async function load(page = meta.value.current_page, silent = false) {
  if (!silent) loading.value = true
  loadError.value = ''
  const q = new URLSearchParams({ page: String(page), per_page: '15' })
  if (props.jobId) q.set('backup_job_id', String(props.jobId))
  if (status.value) q.set('status', status.value)
  try {
    const res = await api<Paginated<Backup>>(`/backups?${q}`)
    if (!res.data.length && page > res.meta.last_page && res.meta.last_page > 0) return load(res.meta.last_page, silent)
    items.value = res.data
    meta.value = res.meta
  } catch (e) {
    if (!silent) loadError.value = errorMessage(e, 'Не удалось загрузить бекапы')
  } finally {
    loading.value = false
  }
}
defineExpose({ reload: () => load() })

// автообновление, пока есть выполняющиеся бекапы
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  load(1)
  timer = setInterval(() => {
    if (items.value.some((b) => isActive(b.status))) load(meta.value.current_page, true)
  }, 4000)
})
onUnmounted(() => clearInterval(timer))
watch(() => [props.jobId, status.value], () => load(1))

// --- детали (лог/ошибка) ---
const details = ref<Backup | null>(null)
const detailsLoading = ref(false)
const detailsError = ref('')
async function openDetails(b: Backup) {
  details.value = b
  detailsError.value = ''
  detailsLoading.value = true
  try {
    details.value = (await api<{ data: Backup }>(`/backups/${b.id}`)).data
  } catch (e) {
    detailsError.value = errorMessage(e, 'Не удалось загрузить лог')
  } finally {
    detailsLoading.value = false
  }
}

// --- скачивание ---
const actionError = ref('')
const downloading = ref<number | null>(null)
async function download(b: Backup) {
  actionError.value = ''
  downloading.value = b.id
  try {
    await downloadBackup(b.id, b.file_name)
  } catch (e) {
    actionError.value = errorMessage(e, 'Файл недоступен для скачивания')
  } finally {
    downloading.value = null
  }
}

// --- удаление ---
const removing = ref<Backup | null>(null)
const removeLoading = ref(false)
const removeError = ref('')
async function remove() {
  if (!removing.value) return
  removeLoading.value = true
  removeError.value = ''
  try {
    await api(`/backups/${removing.value.id}`, { method: 'DELETE' })
    removing.value = null
    await load()
  } catch (e) {
    removeError.value = errorMessage(e)
  } finally {
    removeLoading.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center gap-2">
      <select :value="jobId ?? ''" :class="[inputClass.replace('w-full', 'w-auto'), 'max-w-64']" aria-label="Задание" @change="emit('update:jobId', ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null)">
        <option value="">Все задания</option>
        <option v-for="j in jobs" :key="j.id" :value="j.id">{{ j.name }}</option>
      </select>
      <select v-model="status" :class="[inputClass.replace('w-full', 'w-auto')]" aria-label="Статус">
        <option value="">Любой статус</option>
        <option value="pending">В очереди</option>
        <option value="running">Выполняется</option>
        <option value="success">Успешно</option>
        <option value="failed">Ошибка</option>
      </select>
      <button :class="btnClass.outline" title="Обновить" @click="load()"><Icon name="refresh" :class="loading && 'animate-spin'" />Обновить</button>
    </div>

    <div v-if="actionError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ actionError }}</div>
    <div v-if="loadError" role="alert" class="flex items-center gap-3 rounded-lg border bg-card px-4 py-3 text-sm text-destructive">
      {{ loadError }}
      <button :class="[btnClass.outlineSm, 'ml-auto']" @click="load()">Повторить</button>
    </div>
    <div v-else-if="loading && !items.length" class="rounded-xl border p-10 text-center text-sm text-muted-foreground">Загрузка…</div>
    <div v-else-if="!items.length" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">Бекапов не найдено.</div>
    <template v-else>
      <div class="overflow-x-auto rounded-xl border" :class="loading && 'opacity-60'">
        <table class="w-full text-sm">
          <thead class="border-b bg-muted/40 text-left text-xs text-muted-foreground">
            <tr>
              <th class="px-3 py-2 font-medium">Задание</th>
              <th class="px-3 py-2 font-medium">Статус</th>
              <th class="px-3 py-2 font-medium">Начало</th>
              <th class="px-3 py-2 font-medium">Длительность</th>
              <th class="px-3 py-2 text-right font-medium">Размер</th>
              <th class="px-3 py-2 font-medium">SHA-256</th>
              <th class="px-3 py-2" />
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="b in items" :key="b.id">
              <td class="px-3 py-2">
                <div class="font-medium">{{ b.backup_job?.name ?? `#${b.backup_job_id}` }}</div>
                <div class="text-xs text-muted-foreground">{{ b.backup_job ? typeLabel[b.backup_job.type] : '' }} · {{ b.trigger === 'manual' ? 'вручную' : 'по расписанию' }}</div>
              </td>
              <td class="px-3 py-2"><StatusBadge :tone="statusBadge(b.status).tone" :label="statusBadge(b.status).label" /></td>
              <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(b.started_at ?? b.created_at) }}</td>
              <td class="px-3 py-2 whitespace-nowrap tabular-nums">{{ formatDuration(b.duration_seconds) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap tabular-nums">{{ formatBytes(b.size_bytes) }}</td>
              <td class="px-3 py-2">
                <div v-if="b.sha256" class="flex items-center gap-2">
                  <span class="font-mono text-xs" :title="b.sha256">{{ b.sha256.slice(0, 12) }}…</span>
                  <CopyButton :text="b.sha256" label="" />
                </div>
                <span v-else class="text-muted-foreground">—</span>
              </td>
              <td class="px-3 py-2">
                <div class="flex justify-end">
                  <button :class="btnClass.ghostSm" :title="b.error ? 'Лог и ошибка' : 'Лог'" @click="openDetails(b)"><Icon name="eye" /></button>
                  <button :class="btnClass.ghostSm" title="Скачать" :disabled="b.status !== 'success' || !b.has_file || downloading === b.id" @click="download(b)">
                    <Icon :name="downloading === b.id ? 'loader' : 'download'" :class="downloading === b.id && 'animate-spin'" />
                  </button>
                  <button :class="btnClass.ghostSm" title="Удалить" :disabled="isActive(b.status)" @click="removeError = ''; removing = b"><Icon name="trash" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="load" />
    </template>

    <Modal v-if="details" wide :title="`Бекап #${details.id}`" :description="details.backup_job?.name" @close="details = null">
      <div class="flex flex-wrap items-center gap-2">
        <StatusBadge :tone="statusBadge(details.status).tone" :label="statusBadge(details.status).label" />
        <span class="text-xs text-muted-foreground">{{ formatBytes(details.size_bytes) }} · {{ formatDuration(details.duration_seconds) }} · {{ formatDate(details.finished_at) }}</span>
      </div>
      <div v-if="details.error" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm break-words text-destructive">{{ details.error }}</div>
      <div v-if="details.sha256" class="grid gap-1 text-xs">
        <div class="flex items-center gap-2"><span class="text-muted-foreground">SHA-256</span><CopyButton :text="details.sha256" /></div>
        <code class="font-mono break-all">{{ details.sha256 }}</code>
      </div>
      <div v-if="detailsLoading" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка лога…</div>
      <div v-else-if="detailsError" role="alert" class="text-sm text-destructive">{{ detailsError }}</div>
      <pre v-else-if="details.log" class="max-h-80 overflow-auto rounded-lg border bg-muted/40 p-3 font-mono text-xs whitespace-pre-wrap">{{ details.log }}</pre>
      <p v-else class="text-sm text-muted-foreground">Лог пуст.</p>
      <div class="flex justify-end"><button type="button" :class="btnClass.outline" @click="details = null">Закрыть</button></div>
    </Modal>

    <ConfirmDialog
      v-if="removing"
      title="Удалить бекап?"
      :text="`Бекап #${removing.id} и его файл будут удалены без возможности восстановления.`"
      :loading="removeLoading"
      :error="removeError"
      @confirm="remove"
      @close="removing = null"
    />
  </div>
</template>
