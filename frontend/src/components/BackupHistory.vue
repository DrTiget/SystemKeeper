<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import { type Backup, downloadBackupFile, isActive } from '@/api/backups'
import Icon from '@/components/Icon.vue'
import { btnClass, formatBytes, formatDate } from '@/lib/ui'

/** source — «servers/5» или «databases/3» */
const props = defineProps<{ source: string; disabledReason?: string }>()

const items = ref<Backup[]>([])
const loaded = ref(false)
const error = ref('')
const starting = ref(false)
const expanded = ref<number | null>(null)

const running = computed(() => items.value.some(isActive))

let timer: ReturnType<typeof setTimeout> | undefined
async function load() {
  clearTimeout(timer)
  try {
    items.value = (await api<{ data: Backup[] }>(`/${props.source}/backups?per_page=10`)).data
  } catch {
    error.value = 'Не удалось загрузить историю бекапов'
  } finally {
    loaded.value = true
  }
  // пока бекап идёт — обновляем, чтобы был виден журнал
  if (running.value) timer = setTimeout(load, 2000)
}

async function start() {
  starting.value = true
  error.value = ''
  try {
    const b = (await api<{ data: Backup }>(`/${props.source}/backups`, { method: 'POST' })).data
    expanded.value = b.id
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : 'Не удалось запустить бекап'
  } finally {
    starting.value = false
  }
}

async function remove(b: Backup) {
  if (!confirm('Удалить этот бекап вместе с файлами?')) return
  try {
    await api(`/backups/${b.id}`, { method: 'DELETE' })
    items.value = items.value.filter((x) => x.id !== b.id)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Не удалось удалить'
  }
}

async function download(b: Backup, name: string) {
  try {
    await downloadBackupFile(b, name)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Не удалось скачать'
  }
}

function duration(b: Backup): string {
  if (!b.started_at) return ''
  const s = Math.round(((b.finished_at ? new Date(b.finished_at) : new Date()).getTime() - new Date(b.started_at).getTime()) / 1000)
  return s < 60 ? `${s} с` : `${Math.floor(s / 60)} мин ${s % 60} с`
}

const STATUS = {
  queued: { label: 'В очереди', dot: 'bg-muted-foreground' },
  running: { label: 'Выполняется', dot: 'bg-amber-500 animate-pulse' },
  success: { label: 'Готово', dot: 'bg-emerald-500' },
  failed: { label: 'Ошибка', dot: 'bg-destructive' },
}

onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))
defineExpose({ load })
</script>

<template>
  <div class="grid gap-3">
    <div class="flex items-center justify-between gap-3">
      <h3 class="font-semibold">Бекапы</h3>
      <button :class="btnClass.primary" :disabled="starting || running || !!disabledReason" :title="disabledReason" @click="start">
        <Icon :name="starting || running ? 'loader' : 'play'" :class="(starting || running) && 'animate-spin'" />{{ running ? 'Выполняется…' : 'Запустить сейчас' }}
      </button>
    </div>
    <p v-if="disabledReason" class="text-sm text-muted-foreground">{{ disabledReason }}</p>
    <p v-if="error" class="text-sm whitespace-pre-wrap text-destructive">{{ error }}</p>

    <p v-if="loaded && !items.length" class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">Бекапов ещё не было</p>
    <ul v-else-if="items.length" class="divide-y rounded-lg border text-sm">
      <li v-for="b in items" :key="b.id">
        <button type="button" class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-accent/40" @click="expanded = expanded === b.id ? null : b.id">
          <span class="size-2 shrink-0 rounded-full" :class="STATUS[b.status].dot" :title="STATUS[b.status].label" />
          <span class="grid min-w-0 flex-1 gap-0.5">
            <span class="flex items-baseline justify-between gap-2">
              <span :class="b.status === 'failed' && 'text-destructive'">{{ formatDate(b.started_at ?? b.created_at) }}</span>
              <span v-if="b.size !== null" class="text-xs text-muted-foreground">{{ formatBytes(b.size) }}</span>
            </span>
            <span class="truncate text-xs text-muted-foreground">
              {{ b.status === 'success' ? '' : STATUS[b.status].label + ' · ' }}{{ b.trigger === 'manual' ? `вручную${b.user ? ', ' + b.user.name : ''}` : 'по расписанию' }}<template v-if="b.started_at"> · {{ duration(b) }}</template>
            </span>
          </span>
          <Icon name="chevron-right" class="size-4 shrink-0 text-muted-foreground transition-transform" :class="expanded === b.id && 'rotate-90'" />
        </button>
        <div v-if="expanded === b.id" class="grid gap-3 border-t bg-muted/30 px-3 py-3">
          <p v-if="b.error" class="whitespace-pre-wrap text-destructive">{{ b.error }}</p>
          <ul v-if="b.log.length" class="grid max-h-60 gap-0.5 overflow-y-auto font-mono text-xs text-muted-foreground">
            <li v-for="(l, i) in b.log" :key="i" class="whitespace-pre-wrap break-all"><span class="opacity-60">{{ new Date(l.at).toLocaleTimeString('ru-RU') }}</span> {{ l.message }}</li>
          </ul>
          <div v-if="b.files.length" class="grid gap-1.5">
            <button v-for="f in b.files" :key="f.name" :class="btnClass.outline" class="justify-start" @click="download(b, f.name)">
              <Icon name="download" /><span class="min-w-0 truncate">{{ f.name }}</span><span class="ml-auto text-muted-foreground">{{ formatBytes(f.size) }}</span>
            </button>
          </div>
          <div v-if="!isActive(b)" class="flex justify-end">
            <button class="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-destructive" @click="remove(b)"><Icon name="trash" class="size-3.5" />Удалить бекап</button>
          </div>
        </div>
      </li>
    </ul>
  </div>
</template>
