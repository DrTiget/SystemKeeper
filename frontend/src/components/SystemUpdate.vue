<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ACTIVE_STATUSES, checkUpdates, getVersion, startUpdate, type SystemVersion } from '@/api/system'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { btnClass } from '@/lib/ui'
import { useAuthStore } from '@/stores/auth'

// Версия в меню; администратору — «Доступна vX» и обновление в один клик
const auth = useAuthStore()
const info = ref<SystemVersion | null>(null)
const open = ref(false)
const busy = ref(false)
const error = ref('')
let timer: ReturnType<typeof setTimeout> | undefined
let startedFrom: string | null = null

const active = computed(() => !!info.value && ACTIVE_STATUSES.includes(info.value.updater.status))
const showUpdate = computed(() => auth.user?.is_admin && (info.value?.update_available || active.value))

const stageLabel: Record<string, string> = {
  pending: 'Запрос принят',
  waiting: 'Жду окончания бекапов',
  backup: 'Дамп базы',
  pulling: 'Скачиваю новую версию',
  restarting: 'Перезапускаю',
  done: 'Готово',
  failed: 'Ошибка',
  rolled_back: 'Откат на прежнюю версию',
}

async function load() {
  clearTimeout(timer)
  try {
    info.value = await getVersion()
    // Новая версия поднялась — перезагружаем страницу, чтобы взять новый фронтенд
    if (startedFrom && info.value.current !== startedFrom) location.reload()
  } catch {
    // во время перезапуска API недоступен — просто ждём
  }
  timer = setTimeout(load, active.value || startedFrom ? 3000 : 600_000)
}

async function update() {
  busy.value = true
  error.value = ''
  try {
    startedFrom = info.value?.current ?? null
    info.value = await startUpdate()
    load()
  } catch (e) {
    startedFrom = null
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    busy.value = false
  }
}

async function check() {
  busy.value = true
  try {
    info.value = await checkUpdates()
  } finally {
    busy.value = false
  }
}

onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <div v-if="info" class="px-2 pb-1 text-xs text-sidebar-foreground/60">
    <button
      v-if="showUpdate"
      class="flex w-full items-center gap-2 rounded-md bg-sidebar-accent px-2 py-1.5 text-left text-sidebar-accent-foreground hover:ring-1 hover:ring-primary"
      @click="open = true"
    >
      <Icon :name="active ? 'loader' : 'download'" class="size-3.5 shrink-0" :class="active && 'animate-spin'" />
      <span class="truncate">{{ active ? stageLabel[info.updater.status] : `Доступна ${info.latest?.version}` }}</span>
    </button>
    <div v-else class="px-2" :title="info.checked_at ? `Проверено ${new Date(info.checked_at).toLocaleString()}` : ''">SystemKeeper {{ info.current }}</div>

    <Modal v-if="open" :title="`Обновление до ${info.latest?.version ?? ''}`" wide @close="open = false">
      <div class="flex flex-col gap-4 text-sm">
        <p class="text-muted-foreground">
          Установлена {{ info.current }}. Перед обновлением делается дамп базы; если новая версия не запустится, вернётся прежняя.
          Идущие бекапы не прерываются — обновление начнётся после них.
        </p>
        <div v-if="info.latest?.notes" class="max-h-64 overflow-auto rounded-md border bg-muted/40 p-3 text-xs whitespace-pre-wrap">{{ info.latest.notes }}</div>
        <a v-if="info.latest" :href="info.latest.url" target="_blank" rel="noopener" class="text-xs text-primary hover:underline">Описание релиза на GitHub</a>

        <div v-if="info.updater.status !== 'idle'" class="rounded-md border p-3" :class="['failed', 'rolled_back'].includes(info.updater.status) && 'border-destructive text-destructive'">
          <div class="font-medium">{{ stageLabel[info.updater.status] }}</div>
          <div v-if="info.updater.message" class="text-xs text-muted-foreground">{{ info.updater.message }}</div>
        </div>
        <p v-if="!info.updater.available" class="text-xs text-muted-foreground">
          Обновлятор не запущен. На сервере выполните: <code class="rounded bg-muted px-1">systemkeeper update</code>
        </p>
        <p v-if="error" class="text-destructive">{{ error }}</p>

        <div class="flex justify-end gap-2">
          <button type="button" :class="btnClass.outline" :disabled="busy || active" @click="check">Проверить снова</button>
          <button type="button" :class="btnClass.primary" :disabled="busy || active || !info.updater.available || !info.update_available" @click="update">
            {{ active ? 'Обновляется…' : 'Обновить' }}
          </button>
        </div>
      </div>
    </Modal>
  </div>
</template>
