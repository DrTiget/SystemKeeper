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
    <div class="flex flex-wrap items-center gap-3">
      <button :class="btnClass.primary" :disabled="starting || running || !!disabledReason" :title="disabledReason" @click="start">
        <Icon :name="starting || running ? 'loader' : 'play'" :class="(starting || running) && 'animate-spin'" />{{ running ? 'Бекап выполняется…' : 'Запустить бекап' }}
      </button>
      <span v-if="disabledReason" class="text-sm text-muted-foreground">{{ disabledReason }}</span>
    </div>
    <p v-if="error" class="text-sm whitespace-pre-wrap text-destructive">{{ error }}</p>

    <p v-if="loaded && !items.length" class="text-sm text-muted-foreground">Бекапов ещё не было.</p>
    <ul v-else-if="items.length" class="divide-y rounded-lg border text-sm">
      <li v-for="b in items" :key="b.id">
        <button type="button" class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2.5 text-left hover:bg-accent/40" @click="expanded = expanded === b.id ? null : b.id">
          <span class="inline-flex w-28 items-center gap-1.5" :class="b.status === 'failed' && 'text-destructive'"><span class="size-2 rounded-full" :class="STATUS[b.status].dot" />{{ STATUS[b.status].label }}</span>
          <span>{{ formatDate(b.started_at ?? b.created_at) }}</span>
          <span class="text-muted-foreground">{{ b.trigger === 'manual' ? `вручную${b.user ? ', ' + b.user.name : ''}` : 'по расписанию' }}</span>
          <span v-if="b.started_at" class="text-muted-foreground">{{ duration(b) }}</span>
          <span v-if="b.size !== null" class="ml-auto text-muted-foreground">{{ formatBytes(b.size) }}</span>
          <Icon name="chevron-right" class="size-4 text-muted-foreground transition-transform" :class="[b.size === null && 'ml-auto', expanded === b.id && 'rotate-90']" />
        </button>
        <div v-if="expanded === b.id" class="grid gap-3 border-t bg-muted/30 px-3 py-3">
          <p v-if="b.error" class="whitespace-pre-wrap text-destructive">{{ b.error }}</p>
          <ul v-if="b.log.length" class="grid gap-0.5 font-mono text-xs text-muted-foreground">
            <li v-for="(l, i) in b.log" :key="i" class="whitespace-pre-wrap"><span class="opacity-60">{{ new Date(l.at).toLocaleTimeString('ru-RU') }}</span> {{ l.message }}</li>
          </ul>
          <div v-if="b.files.length" class="flex flex-wrap gap-2">
            <button v-for="f in b.files" :key="f.name" :class="btnClass.outline" @click="download(b, f.name)">
              <Icon name="download" />{{ f.name }} <span class="text-muted-foreground">{{ formatBytes(f.size) }}</span>
            </button>
          </div>
          <div v-if="!isActive(b)" class="flex justify-end">
            <button :class="btnClass.destructive" @click="remove(b)"><Icon name="trash" />Удалить бекап</button>
          </div>
        </div>
      </li>
    </ul>
  </div>
</template>
