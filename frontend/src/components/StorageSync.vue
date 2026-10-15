<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { BackupStorage, StorageSyncState } from '@/api/storages'
import Icon from '@/components/Icon.vue'
import { btnClass, formatBytes } from '@/lib/ui'

// «Скопировать сюда все бекапы»: что можно скопировать, запуск и ход синхронизации
const props = defineProps<{ storage: BackupStorage }>()
const emit = defineEmits<{ synced: [] }>()

const state = ref<StorageSyncState | null>(null)
const addToSources = ref(true)
const starting = ref(false)
const error = ref('')
let timer: ReturnType<typeof setTimeout> | undefined

const last = computed(() => state.value?.last ?? null)
const active = computed(() => last.value?.status === 'queued' || last.value?.status === 'running')
const percent = computed(() => (last.value?.bytes_total ? Math.round((last.value.bytes_done / last.value.bytes_total) * 100) : 0))

async function load() {
  clearTimeout(timer)
  const wasActive = active.value
  try {
    state.value = (await api<{ data: StorageSyncState }>(`/storages/${props.storage.id}/sync`)).data
  } catch {
    // нет связи — попробуем позже
  }
  if (wasActive && !active.value) emit('synced')
  if (active.value) timer = setTimeout(load, 2000)
}

async function start() {
  starting.value = true
  error.value = ''
  try {
    state.value = (await api<{ data: StorageSyncState }>(`/storages/${props.storage.id}/sync`, { method: 'POST', body: { add_to_sources: addToSources.value } })).data
    if (active.value) timer = setTimeout(load, 2000)
    else emit('synced')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Не удалось запустить'
  } finally {
    starting.value = false
  }
}

onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <div v-if="state && (state.pending.backups || last)" class="grid gap-2 rounded-lg border p-3 text-sm">
    <template v-if="active && last">
      <div class="flex items-center justify-between gap-3">
        <span class="inline-flex items-center gap-2 font-medium"><Icon name="loader" class="size-4 animate-spin" />Копирую бекапы сюда</span>
        <span class="text-muted-foreground">{{ last.done + last.failed }} из {{ last.total }} · {{ formatBytes(last.bytes_done) }} / {{ formatBytes(last.bytes_total) }}</span>
      </div>
      <div class="h-2 overflow-hidden rounded-full bg-foreground/10"><div class="h-full bg-primary transition-all" :style="{ width: `${percent}%` }" /></div>
      <div v-if="last.current" class="truncate font-mono text-xs text-muted-foreground">{{ last.current }}</div>
    </template>

    <template v-else>
      <div v-if="last" class="flex flex-wrap items-center gap-x-2 text-muted-foreground">
        <span class="size-2 rounded-full" :class="last.status === 'failed' ? 'bg-destructive' : last.failed ? 'bg-amber-500' : 'bg-emerald-500'" />
        Последняя синхронизация {{ last.finished_at ? new Date(last.finished_at).toLocaleString() : '' }}: скопировано {{ last.done }}<template v-if="last.failed">, не удалось {{ last.failed }}</template>
      </div>
      <pre v-if="last?.error" class="max-h-32 overflow-auto rounded bg-muted/50 p-2 text-xs whitespace-pre-wrap text-destructive">{{ last.error }}</pre>

      <div v-if="state.pending.backups" class="flex flex-wrap items-center justify-between gap-3">
        <div class="grid gap-1">
          <span>В других хранилищах есть <b>{{ state.pending.backups }}</b> бекапов ({{ formatBytes(state.pending.size) }}), которых здесь нет</span>
          <label class="inline-flex items-center gap-2 text-muted-foreground">
            <input v-model="addToSources" type="checkbox" class="size-4 accent-primary" />
            Новые бекапы этих серверов и баз тоже сохранять сюда
          </label>
        </div>
        <button :class="btnClass.outline" :disabled="starting" @click="start">
          <Icon :name="starting ? 'loader' : 'download'" :class="starting && 'animate-spin'" />Скопировать сюда
        </button>
      </div>
      <p v-else class="text-muted-foreground">Все бекапы уже есть в этом хранилище.</p>
      <p v-if="error" class="text-destructive">{{ error }}</p>
    </template>
  </div>
</template>
