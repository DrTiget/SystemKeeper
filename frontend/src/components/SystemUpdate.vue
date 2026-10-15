<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ACTIVE_STATUSES, type ChangelogEntry, checkUpdates, getChangelog, getVersion, startUpdate, type SystemVersion } from '@/api/system'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { renderMarkdown } from '@/lib/markdown'
import { btnClass, formatDate } from '@/lib/ui'
import { useAuthStore } from '@/stores/auth'

// Версия в меню: по клику — «Что нового» и проверка обновлений; администратору — «Доступна vX» и обновление в один клик
const auth = useAuthStore()
const info = ref<SystemVersion | null>(null)
const open = ref(false)
const busy = ref(false)
const error = ref('')
const checked = ref(false)
const changelog = ref<ChangelogEntry[] | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined
let startedFrom: string | null = null

/** Этапы обновления — как их проходит контейнер updater */
const STEPS = [
  { key: 'waiting', title: 'Ожидание бекапов', text: 'Идущие бекапы и синхронизации не прерываются: обновление ждёт их окончания (до 6 часов), новые бекапы на это время не стартуют.' },
  { key: 'backup', title: 'Дамп базы', text: 'Полный дамп базы SystemKeeper в каталог backups установки, обычно /opt/systemkeeper/backups (хранятся 3 последних). Не получился — обновление отменяется.' },
  { key: 'pulling', title: 'Скачивание версии', text: 'Скачиваются файлы релиза и образ новой версии; работающие сервисы пока не трогаются.' },
  { key: 'restarting', title: 'Перезапуск', text: 'Сервисы перезапускаются на новой версии, миграции базы применяются при старте. Интерфейс недоступен около минуты.' },
  { key: 'done', title: 'Проверка', text: 'Новая версия должна ответить на проверку здоровья. Не ответила — возвращается прежняя версия и база из дампа.' },
] as const
const stepIndex = computed(() => {
  const s = info.value?.updater.status
  if (s === 'pending') return 0
  const i = STEPS.findIndex((x) => x.key === s)
  return i === -1 ? -1 : i
})
const finished = computed(() => ['done', 'failed', 'rolled_back'].includes(info.value?.updater.status ?? ''))

const active = computed(() => !!info.value && ACTIVE_STATUSES.includes(info.value.updater.status))
const busyCount = computed(() => (info.value ? info.value.busy.backups + info.value.busy.syncs : 0))
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
  timer = setTimeout(load, active.value || startedFrom ? 3000 : open.value ? 15_000 : 600_000)
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
  error.value = ''
  try {
    info.value = await checkUpdates()
    checked.value = true
    loadChangelog()
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    busy.value = false
  }
}

async function loadChangelog() {
  try {
    changelog.value = await getChangelog()
  } catch {
    changelog.value ??= []
  }
}

function show() {
  open.value = true
  checked.value = false
  load()
  loadChangelog()
}

onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <div v-if="info" class="px-2 pb-1 text-xs text-sidebar-foreground/60">
    <button
      v-if="showUpdate"
      class="flex w-full items-center gap-2 rounded-md bg-sidebar-accent px-2 py-1.5 text-left text-sidebar-accent-foreground hover:ring-1 hover:ring-primary"
      @click="show"
    >
      <Icon :name="active ? 'loader' : 'download'" class="size-3.5 shrink-0" :class="active && 'animate-spin'" />
      <span class="truncate">{{ active ? stageLabel[info.updater.status] : `Доступна ${info.latest?.version}` }}</span>
    </button>
    <button v-else class="w-full rounded-md px-2 py-1 text-left hover:bg-sidebar-accent hover:text-sidebar-accent-foreground" title="Что нового и проверка обновлений" @click="show">
      SystemKeeper {{ info.current }}
    </button>

    <Modal v-if="open" :title="info.update_available || active ? `Обновление до ${info.latest?.version ?? ''}` : `SystemKeeper ${info.current}`" wide @close="open = false">
      <div class="flex flex-col gap-4 text-sm">
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3">
          <div class="grid gap-0.5">
            <span class="font-medium">
              <template v-if="info.update_available">Доступна {{ info.latest?.version }}, установлена {{ info.current }}</template>
              <template v-else-if="info.check_error">Не удалось проверить обновления</template>
              <template v-else-if="info.latest">Установлена последняя версия</template>
              <template v-else>Установлена {{ info.current }}</template>
            </span>
            <span class="text-xs text-muted-foreground">
              <template v-if="info.check_error">{{ info.check_error }} · </template>
              {{ info.checked_at ? `Проверено ${formatDate(info.checked_at)}` : 'Ещё не проверялось' }}<template v-if="checked && !info.check_error"> — только что</template>
            </span>
          </div>
          <button v-if="auth.user?.is_admin" type="button" :class="btnClass.outline" class="h-8! px-3!" :disabled="busy || active" @click="check">
            <Icon name="refresh" :class="busy && 'animate-spin'" />Проверить
          </button>
        </div>

        <template v-if="auth.user?.is_admin && (info.update_available || active)">
          <ol class="grid gap-0">
            <li v-for="(step, i) in STEPS" :key="step.key" class="relative grid grid-cols-[1.5rem_minmax(0,1fr)] gap-x-3 pb-3 last:pb-0">
              <span v-if="i < STEPS.length - 1" class="absolute top-6 bottom-0 left-3 w-px -translate-x-1/2 bg-border" />
              <span
                class="relative z-10 flex size-6 items-center justify-center rounded-full border text-[0.7rem] font-semibold"
                :class="
                  active && i === stepIndex
                    ? 'border-primary bg-primary text-primary-foreground'
                    : active && i < stepIndex
                      ? 'border-primary text-primary'
                      : 'bg-background text-muted-foreground'
                "
              >
                <Icon v-if="active && i === stepIndex" name="loader" class="size-3.5 animate-spin" />
                <Icon v-else-if="active && i < stepIndex" name="check" class="size-3.5" />
                <template v-else>{{ i + 1 }}</template>
              </span>
              <div class="grid gap-0.5">
                <span class="font-medium" :class="active && i === stepIndex && 'text-primary'">{{ step.title }}</span>
                <span class="text-xs text-muted-foreground">{{ step.text }}</span>
              </div>
            </li>
          </ol>
          <p v-if="active" class="text-xs text-muted-foreground">
            {{ info.updater.message }}<template v-if="info.updater.updated_at"> · {{ formatDate(info.updater.updated_at) }}</template>.
            Окно можно закрыть — обновление идёт на сервере; страница перезагрузится сама, когда поднимется новая версия.
          </p>
          <p v-if="!info.updater.available" class="text-xs text-muted-foreground">
            Обновлятор не запущен. На сервере выполните: <code class="rounded bg-muted px-1">systemkeeper update</code>
          </p>
          <p v-if="busyCount && !active" class="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-xs">
            Сейчас идут бекапы ({{ info.busy.backups }}<template v-if="info.busy.syncs">, синхронизаций хранилищ — {{ info.busy.syncs }}</template>).
            Обновление перезапускает сервисы и прервало бы их — обновить можно будет после окончания.
          </p>
          <div class="flex justify-end">
            <button type="button" :class="btnClass.primary" :disabled="busy || active || busyCount > 0 || !info.updater.available || !info.update_available" @click="update">
              {{ active ? 'Обновляется…' : `Обновить до ${info.latest?.version}` }}
            </button>
          </div>
        </template>
        <div
          v-if="finished"
          class="grid gap-0.5 rounded-md border p-3 text-xs"
          :class="info.updater.status === 'done' ? 'border-emerald-500/40 bg-emerald-500/5' : 'border-destructive/50 bg-destructive/5 text-destructive'"
        >
          <span class="font-medium">
            Последнее обновление<template v-if="info.updater.from && info.updater.to"> {{ info.updater.from }} → {{ info.updater.to }}</template>: {{ stageLabel[info.updater.status].toLowerCase() }}
          </span>
          <span v-if="info.updater.message" :class="info.updater.status === 'done' && 'text-muted-foreground'">{{ info.updater.message }}</span>
          <span v-if="info.updater.updated_at" class="text-muted-foreground">{{ formatDate(info.updater.updated_at) }}</span>
          <span v-if="info.updater.status !== 'done'" class="text-muted-foreground">Подробный журнал на сервере: state/update.log в каталоге установки (обычно /opt/systemkeeper)</span>
        </div>
        <p v-if="error" class="text-destructive">{{ error }}</p>

        <div class="grid gap-3">
          <h4 class="font-semibold">Что нового</h4>
          <p v-if="!changelog" class="flex items-center gap-2 text-muted-foreground"><Icon name="loader" class="size-4 animate-spin" />Загрузка…</p>
          <p v-else-if="!changelog.length" class="text-muted-foreground">История изменений недоступна.</p>
          <article
            v-for="r in changelog ?? []"
            :key="r.version"
            class="grid gap-2 rounded-lg border p-3"
            :class="r.is_new ? 'border-primary/50 bg-primary/5' : r.is_current ? 'bg-muted/40' : ''"
          >
            <header class="flex flex-wrap items-center gap-2">
              <span class="font-mono font-semibold">{{ r.version }}</span>
              <span v-if="r.is_new" class="rounded-full bg-primary px-2 py-0.5 text-[0.7rem] font-medium text-primary-foreground">Новое</span>
              <span v-else-if="r.is_current" class="rounded-full border px-2 py-0.5 text-[0.7rem] font-medium">Установлена</span>
              <span v-if="r.published_at" class="text-xs text-muted-foreground">{{ new Date(r.published_at).toLocaleDateString('ru-RU', { dateStyle: 'long' }) }}</span>
              <a v-if="r.url" :href="r.url" target="_blank" rel="noopener" class="ml-auto text-xs text-primary hover:underline">GitHub</a>
            </header>
            <!-- renderMarkdown экранирует HTML -->
            <div class="grid gap-1.5 text-[0.8rem] leading-relaxed text-muted-foreground" v-html="renderMarkdown(r.notes || 'Без описания.')" />
          </article>
        </div>
      </div>
    </Modal>
  </div>
</template>
