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

const meta = computed(() => backup.value?.meta ?? {})
const serverSource = computed(() => (meta.value.source?.type === 'server' ? meta.value.source : null))
const dbSource = computed(() => (meta.value.source?.type === 'database' ? meta.value.source : null))

const ms = (v: number) => (v < 1000 ? `${v} мс` : v < 60_000 ? `${(v / 1000).toFixed(1).replace('.', ',')} с` : `${Math.floor(v / 60_000)} мин ${Math.round((v % 60_000) / 1000)} с`)

/** Во сколько раз сжато: объём файлов (сервер) или SQL (БД) до сжатия к размеру бекапа */
const original = computed(() => meta.value.archive?.uncompressed_size ?? (meta.value.databases?.length ? meta.value.databases.reduce((a, d) => a + (d.uncompressed_size ?? 0), 0) || null : null))
const ratio = computed(() => (original.value && backup.value?.size ? original.value / backup.value.size : null))
const speed = computed(() => {
  const b = backup.value
  if (!b?.size || !b.started_at || !b.finished_at) return null
  const sec = (new Date(b.finished_at).getTime() - new Date(b.started_at).getTime()) / 1000
  return sec > 0 ? b.size / sec : null
})

const LOCK_MODES: Record<string, string> = { single_transaction: 'снимок без блокировки', lock_tables: 'блокировка таблиц', none: 'без блокировок' }
const dbOptions = computed(() => {
  const o = dbSource.value?.options
  if (!o) return []
  return dbSource.value!.engine === 'mysql'
    ? [LOCK_MODES[String(o.lock_mode)] ?? String(o.lock_mode), o.routines && 'процедуры', o.events && 'события', o.triggers && 'триггеры', o.compress && 'gzip'].filter(Boolean)
    : [o.format === 'custom' ? 'формат custom' : 'SQL', o.no_owner && 'без владельцев', o.compress && 'сжатие'].filter(Boolean)
})

const copied = ref<string | null>(null)
async function copy(text: string) {
  await navigator.clipboard?.writeText(text)
  copied.value = text
  setTimeout(() => copied.value === text && (copied.value = null), 1500)
}

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
                <span class="grid min-w-0 flex-1">
                  <span class="truncate font-mono text-xs">{{ f.name }}</span>
                  <button v-if="f.sha256" class="justify-self-start truncate font-mono text-[11px] text-muted-foreground hover:text-foreground" title="SHA-256 — нажмите, чтобы скопировать" @click="copy(f.sha256)">
                    {{ copied === f.sha256 ? 'скопировано' : `sha256 ${f.sha256.slice(0, 16)}…` }}
                  </button>
                </span>
                <span class="text-xs whitespace-nowrap text-muted-foreground">{{ formatBytes(f.size) }}</span>
                <button :class="btnClass.outline" class="h-8! px-3!" @click="download(f.name)"><Icon name="download" />Скачать</button>
              </li>
            </ul>
            <p v-else class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
              {{ isActive(backup) ? 'Файлы появятся, когда бекап завершится' : 'Файлов нет' }}
            </p>
            <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
          </section>

          <section v-if="serverSource" :class="card" class="grid gap-4">
            <div class="flex items-baseline justify-between gap-3">
              <h3 class="font-semibold">Что сохранено</h3>
              <span v-if="meta.archive?.uncompressed_size" class="text-sm text-muted-foreground">до сжатия {{ formatBytes(meta.archive.uncompressed_size) }}</span>
            </div>
            <ul class="grid gap-1 font-mono text-xs">
              <li v-for="p in serverSource.paths" :key="p" class="flex items-center gap-2"><Icon name="folder" class="size-4 text-muted-foreground" />{{ p }}</li>
            </ul>
            <div v-if="serverSource.excludes.length" class="grid gap-1.5">
              <span :class="dt">Исключено</span>
              <div class="flex flex-wrap gap-1.5">
                <span v-for="x in serverSource.excludes" :key="x" class="rounded-md border px-1.5 py-0.5 font-mono text-xs text-muted-foreground">{{ x }}</span>
              </div>
            </div>
            <p v-if="meta.archive?.warnings" class="rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-xs whitespace-pre-wrap">{{ meta.archive.warnings }}</p>
          </section>

          <section v-if="meta.databases?.length" :class="card" class="grid gap-4">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
              <h3 class="font-semibold">Базы</h3>
              <span v-if="dbOptions.length" class="text-sm text-muted-foreground">{{ dbOptions.join(' · ') }}</span>
            </div>
            <div class="overflow-x-auto rounded-lg border">
              <table class="w-full text-sm">
                <thead class="border-b text-left text-muted-foreground">
                  <tr>
                    <th class="px-3 py-2 font-medium">База</th>
                    <th class="px-3 py-2 text-right font-medium">В СУБД</th>
                    <th class="px-3 py-2 text-right font-medium">SQL</th>
                    <th class="px-3 py-2 text-right font-medium">Дамп</th>
                    <th class="px-3 py-2 text-right font-medium">Время</th>
                  </tr>
                </thead>
                <tbody class="divide-y">
                  <tr v-for="d in meta.databases" :key="d.name">
                    <td class="px-3 py-2">
                      <span class="inline-flex items-center gap-1.5 font-mono text-xs">
                        <span class="size-2 rounded-full" :class="d.status === 'success' ? 'bg-emerald-500' : 'bg-destructive'" />{{ d.name }}
                      </span>
                      <div v-if="d.error" class="mt-1 text-xs whitespace-pre-wrap text-destructive">{{ d.error }}</div>
                    </td>
                    <td class="px-3 py-2 text-right whitespace-nowrap text-muted-foreground">{{ d.size !== null ? formatBytes(d.size) : '—' }}</td>
                    <td class="px-3 py-2 text-right whitespace-nowrap text-muted-foreground">{{ d.uncompressed_size ? formatBytes(d.uncompressed_size) : '—' }}</td>
                    <td class="px-3 py-2 text-right whitespace-nowrap">{{ formatBytes(d.dump_size) }}</td>
                    <td class="px-3 py-2 text-right whitespace-nowrap text-muted-foreground">{{ ms(d.duration_ms) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-if="dbSource && (dbSource.exclude_tables.length || dbSource.schema_only_tables.length)" class="grid gap-2 text-xs">
              <div v-if="dbSource.exclude_tables.length" class="grid gap-1">
                <span :class="dt">Не вошли таблицы</span>
                <div class="flex flex-wrap gap-1.5"><span v-for="t in dbSource.exclude_tables" :key="t" class="rounded-md border px-1.5 py-0.5 font-mono text-muted-foreground">{{ t }}</span></div>
              </div>
              <div v-if="dbSource.schema_only_tables.length" class="grid gap-1">
                <span :class="dt">Только структура</span>
                <div class="flex flex-wrap gap-1.5"><span v-for="t in dbSource.schema_only_tables" :key="t" class="rounded-md border px-1.5 py-0.5 font-mono text-muted-foreground">{{ t }}</span></div>
              </div>
            </div>
          </section>

          <section v-if="meta.pre_command" :class="card" class="grid gap-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <h3 class="font-semibold">Команда перед бекапом</h3>
              <span class="inline-flex items-center gap-2 text-sm">
                <span class="size-2 rounded-full" :class="meta.pre_command.exit_code === 0 ? 'bg-emerald-500' : 'bg-destructive'" />
                {{ meta.pre_command.exit_code === 0 ? 'выполнена' : `код ${meta.pre_command.exit_code ?? '—'}${meta.pre_command.continued_on_error ? ', бекап продолжен' : ''}` }}
                <span class="text-muted-foreground">· {{ ms(meta.pre_command.duration_ms) }}</span>
              </span>
            </div>
            <pre class="overflow-x-auto rounded-lg border bg-muted/30 p-3 font-mono text-xs whitespace-pre-wrap">{{ meta.pre_command.command }}</pre>
            <details v-if="meta.pre_command.output" class="text-sm">
              <summary class="cursor-pointer text-muted-foreground hover:text-foreground">Вывод команды</summary>
              <pre class="mt-2 max-h-72 overflow-auto rounded-lg border bg-muted/30 p-3 font-mono text-xs whitespace-pre-wrap">{{ meta.pre_command.output }}</pre>
            </details>
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
            <div v-if="ratio" class="grid gap-0.5">
              <dt :class="dt">{{ meta.archive ? 'До сжатия' : 'SQL до сжатия' }}</dt>
              <dd>{{ formatBytes(original!) }} <span class="text-muted-foreground">· сжато в {{ ratio.toFixed(1).replace('.', ',') }} раза</span></dd>
            </div>
            <div v-if="speed" class="grid gap-0.5">
              <dt :class="dt">Скорость</dt>
              <dd>{{ formatBytes(speed) }}/с</dd>
            </div>
            <template v-if="meta.host">
              <div class="grid gap-0.5 border-t pt-3">
                <dt :class="dt">Хост</dt>
                <dd class="font-mono text-xs">{{ meta.host.hostname ?? '—' }}</dd>
              </div>
              <div v-if="meta.host.os || meta.host.kernel" class="grid gap-0.5">
                <dt :class="dt">Система</dt>
                <dd>{{ meta.host.os }}<span v-if="meta.host.kernel" class="block text-xs text-muted-foreground">{{ meta.host.kernel }}</span></dd>
              </div>
              <div v-for="(v, k) in meta.host.tools" :key="k" class="grid gap-0.5">
                <dt :class="dt">{{ k }}</dt>
                <dd class="text-xs">{{ v }}</dd>
              </div>
            </template>
            <template v-if="dbSource">
              <div class="grid gap-0.5 border-t pt-3">
                <dt :class="dt">СУБД</dt>
                <dd>{{ dbSource.version ?? (dbSource.engine === 'mysql' ? 'MySQL / MariaDB' : 'PostgreSQL') }}</dd>
              </div>
              <div class="grid gap-0.5">
                <dt :class="dt">Подключение</dt>
                <dd>
                  <span class="font-mono text-xs">{{ dbSource.address }}</span>
                  <span class="block text-xs text-muted-foreground">{{ dbSource.via === 'ssh' ? `через ${dbSource.server ?? 'сервер'}${dbSource.docker_container ? `, контейнер ${dbSource.docker_container}` : ''}` : 'напрямую' }}</span>
                </dd>
              </div>
            </template>
            <div v-else-if="serverSource" class="grid gap-0.5">
              <dt :class="dt">Подключение</dt>
              <dd class="font-mono text-xs">{{ serverSource.address }}</dd>
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
