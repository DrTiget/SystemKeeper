<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { type AlertChannel, CHANNEL_TYPES } from '@/api/alerts'
import { api, ApiError } from '@/api/client'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import AlertsTabs from '@/components/alerts/AlertsTabs.vue'
import ChannelFormModal from '@/components/alerts/ChannelFormModal.vue'
import { btnClass, formatDate } from '@/lib/ui'
import { useProjectsStore } from '@/stores/projects'

useProjectsStore().crumbs = []

const items = ref<AlertChannel[]>([])
const loaded = ref(false)
const loadError = ref('')

async function load() {
  try {
    items.value = (await api<{ data: AlertChannel[] }>('/alert-channels')).data
  } catch {
    loadError.value = 'Не удалось загрузить каналы'
  } finally {
    loaded.value = true
  }
}
onMounted(load)

const editing = ref<AlertChannel | null | undefined>(undefined)
function onSaved(c: AlertChannel) {
  const i = items.value.findIndex((x) => x.id === c.id)
  if (i === -1) items.value.push(c)
  else items.value[i] = c
  editing.value = undefined
}

// Тестовое сообщение — результат показываем у самого канала
const testing = ref<number | null>(null)
const tests = ref<Record<number, { ok: boolean; error: string | null }>>({})
async function test(c: AlertChannel) {
  testing.value = c.id
  try {
    tests.value[c.id] = (await api<{ data: { ok: boolean; error: string | null } }>(`/alert-channels/${c.id}/test`, { method: 'POST' })).data
  } catch (e) {
    tests.value[c.id] = { ok: false, error: e instanceof ApiError ? e.message : 'Не удалось отправить' }
  } finally {
    testing.value = null
  }
}

const deleting = ref<AlertChannel | null>(null)
const deleteError = ref('')
async function destroy() {
  deleteError.value = ''
  try {
    await api(`/alert-channels/${deleting.value!.id}`, { method: 'DELETE' })
    items.value = items.value.filter((x) => x.id !== deleting.value!.id)
    deleting.value = null
  } catch (e) {
    deleteError.value = e instanceof ApiError ? e.message : 'Не удалось удалить'
  }
}

const describe = (c: AlertChannel) =>
  c.type === 'telegram' ? `чат ${c.config.chat_id}` : c.type === 'email' ? (c.config.recipients ?? []).join(', ') : c.config.url ?? ''
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div class="space-y-1">
        <h2 class="text-xl font-semibold tracking-tight">Алерты</h2>
        <p class="text-sm text-muted-foreground">Куда приходят уведомления. Канал выбирается в каждом правиле.</p>
      </div>
      <button :class="btnClass.primary" @click="editing = null"><Icon name="plus" />Новый канал</button>
    </header>
    <AlertsTabs />

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>
    <p v-if="!loaded" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>

    <div v-else-if="items.length" class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
      <section v-for="c in items" :key="c.id" class="grid content-start gap-3 rounded-xl border bg-card p-4 shadow-xs" :class="!c.enabled && 'opacity-70'">
        <div class="flex items-start gap-3">
          <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted"><Icon :name="CHANNEL_TYPES[c.type].icon" class="size-4" /></span>
          <div class="grid min-w-0 flex-1 gap-0.5">
            <span class="flex items-center gap-2 font-medium">
              <span class="truncate">{{ c.name }}</span>
              <span v-if="!c.enabled" class="rounded border px-1.5 text-xs font-normal text-muted-foreground">выключен</span>
            </span>
            <span class="truncate text-xs text-muted-foreground">{{ CHANNEL_TYPES[c.type].label }} · <span class="font-mono">{{ describe(c) }}</span></span>
          </div>
        </div>
        <div class="grid gap-1 text-xs text-muted-foreground">
          <span>Правил: {{ c.rules_count }}<template v-if="c.last_sent_at"> · последнее уведомление {{ formatDate(c.last_sent_at) }}</template></span>
          <span v-if="c.last_error" class="break-words text-destructive">Последняя ошибка: {{ c.last_error }}</span>
          <span v-if="tests[c.id]" :class="tests[c.id]!.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive'">
            {{ tests[c.id]!.ok ? 'Тестовое сообщение отправлено' : `Тест не прошёл: ${tests[c.id]!.error}` }}
          </span>
        </div>
        <div class="flex flex-wrap gap-2">
          <button :class="btnClass.outline" class="h-8! px-3!" :disabled="testing === c.id" @click="test(c)">
            <Icon :name="testing === c.id ? 'loader' : 'send'" :class="testing === c.id && 'animate-spin'" />Тест
          </button>
          <button :class="btnClass.outline" class="h-8! px-3!" @click="editing = c"><Icon name="pencil" />Изменить</button>
          <button :class="btnClass.destructive" class="ml-auto h-8! px-2.5!" title="Удалить" @click="(deleting = c), (deleteError = '')"><Icon name="trash" /></button>
        </div>
      </section>
    </div>

    <div v-else-if="!loadError" class="grid justify-items-center gap-3 rounded-xl border border-dashed p-12 text-center">
      <Icon name="send" class="size-8 text-muted-foreground" />
      <div class="grid gap-1">
        <p class="font-medium">Каналов пока нет</p>
        <p class="max-w-md text-sm text-muted-foreground">Без канала алерты видны только здесь. Добавьте Telegram, почту или вебхук — и уведомления будут приходить сами.</p>
      </div>
      <button :class="btnClass.primary" @click="editing = null"><Icon name="plus" />Новый канал</button>
    </div>

    <ChannelFormModal v-if="editing !== undefined" :channel="editing" @close="editing = undefined" @saved="onSaved" />

    <Modal v-if="deleting" title="Удалить канал?" @close="deleting = null">
      <p class="text-sm text-muted-foreground">
        «{{ deleting.name }}» будет удалён<template v-if="deleting.rules_count"> и пропадёт из {{ deleting.rules_count }} правил — проверьте, что у них остались другие каналы</template>.
      </p>
      <p v-if="deleteError" class="mt-3 text-sm text-destructive">{{ deleteError }}</p>
      <div class="mt-6 flex justify-end gap-2">
        <button :class="btnClass.outline" @click="deleting = null">Отмена</button>
        <button :class="btnClass.destructive" @click="destroy">Удалить</button>
      </div>
    </Modal>
  </div>
</template>
