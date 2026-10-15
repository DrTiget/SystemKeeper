<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import { BACKUP_STATUS as STATUS, type LatestBackups } from '@/api/backups'
import { ENGINE_LABELS, type Engine } from '@/api/databases'
import Icon from '@/components/Icon.vue'
import { btnClass, formatBytes, formatDate } from '@/lib/ui'

// Последний бекап каждого сервера и базы: дерево «группа → проект → сервер → базы», свёрнутое запоминается
type Source = LatestBackups['servers'][number] | LatestBackups['databases'][number]
interface ServerNode { key: string; server: LatestBackups['servers'][number] | null; databases: LatestBackups['databases'] }
interface Sizes { count: number; problems: number; total: number; latest: number }
interface ProjectNode extends Sizes { key: string; name: string; id: number | null; servers: ServerNode[] }
interface GroupNode extends Sizes { key: string; name: string; projects: ProjectNode[] }

const data = ref<LatestBackups | null>(null)
const error = ref('')

const COLLAPSED_KEY = 'systemkeeper_latest_collapsed'
const collapsed = ref<string[]>(JSON.parse(localStorage.getItem(COLLAPSED_KEY) ?? '[]'))
watch(collapsed, (v) => localStorage.setItem(COLLAPSED_KEY, JSON.stringify(v)), { deep: true })
const isOpen = (key: string) => !collapsed.value.includes(key)
const toggle = (key: string) => (collapsed.value = isOpen(key) ? [...collapsed.value, key] : collapsed.value.filter((k) => k !== key))

/** Проблема — бекапа нет или последний неудачный */
const problem = (s: Source) => !s.latest || s.latest.status === 'failed'

/** Размер последнего бекапа (у неудачного размера нет) */
const latestSize = (s: Source) => s.latest?.size ?? 0
const sizes = (list: Source[]): Sizes => ({
  count: list.length,
  problems: list.filter(problem).length,
  total: list.reduce((n, s) => n + s.total_size, 0),
  latest: list.reduce((n, s) => n + latestSize(s), 0),
})
const sum = (list: Sizes[]): Sizes => ({
  count: list.reduce((n, x) => n + x.count, 0),
  problems: list.reduce((n, x) => n + x.problems, 0),
  total: list.reduce((n, x) => n + x.total, 0),
  latest: list.reduce((n, x) => n + x.latest, 0),
})

const tree = computed<GroupNode[]>(() => {
  const d = data.value
  if (!d) return []
  const projectNode = (id: number | null, name: string): ProjectNode => {
    const servers: ServerNode[] = d.servers
      .filter((s) => s.project_id === id)
      .map((s) => ({ key: `s:${s.id}`, server: s, databases: d.databases.filter((db) => db.server_id === s.id) }))
    // базы без сервера (прямое подключение); база с сервером показывается только под ним, даже если сервер в другом проекте
    const loose = d.databases.filter((db) => db.project_id === id && !d.servers.some((s) => s.id === db.server_id))
    if (loose.length) servers.push({ key: `p:${id ?? 'none'}:direct`, server: null, databases: loose })
    const all: Source[] = servers.flatMap((n) => [...(n.server ? [n.server] : []), ...n.databases])
    return { key: `p:${id ?? 'none'}`, name, id, servers, ...sizes(all) }
  }
  const groupNode = (id: number | null, name: string): GroupNode => {
    const projects = d.projects.filter((p) => p.group_id === id).map((p) => projectNode(p.id, p.name))
    if (id === null) {
      const none = projectNode(null, 'Без проекта')
      if (none.count) projects.push(none)
    }
    return { key: `g:${id ?? 'none'}`, name, projects, ...sum(projects) }
  }
  return [...d.groups.map((g) => groupNode(g.id, g.name)), groupNode(null, d.groups.length ? 'Без группы' : 'Проекты')].filter((g) => g.count)
})

const sources = computed<Source[]>(() => (data.value ? [...data.value.servers, ...data.value.databases] : []))
const stats = computed(() => ({
  ok: sources.value.filter((s) => s.latest?.status === 'success').length,
  failed: sources.value.filter((s) => s.latest?.status === 'failed').length,
  none: sources.value.filter((s) => !s.latest).length,
  ...sizes(sources.value),
}))
const allKeys = computed(() => tree.value.flatMap((g) => [g.key, ...g.projects.flatMap((p) => [p.key, ...p.servers.filter((s) => s.server && s.databases.length).map((s) => s.key)])]))

let timer: ReturnType<typeof setTimeout> | undefined
async function load() {
  clearTimeout(timer)
  try {
    data.value = (await api<{ data: LatestBackups }>('/backups/latest')).data
    error.value = ''
  } catch {
    error.value = 'Не удалось загрузить бекапы'
  }
  // пока что-то выполняется — обновляем чаще
  timer = setTimeout(load, sources.value.some((s) => s.latest && ['queued', 'running'].includes(s.latest.status)) ? 5000 : 60_000)
}
onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))

const ago = (iso: string | null) => {
  if (!iso) return ''
  const h = (Date.now() - new Date(iso).getTime()) / 3_600_000
  return h < 1 ? 'меньше часа назад' : h < 48 ? `${Math.round(h)} ч назад` : `${Math.round(h / 24)} дн назад`
}
const card = 'rounded-xl border bg-card shadow-xs'
</script>

<template>
  <div class="grid gap-6 p-4">
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold">Последние бекапы</h1>
        <p class="text-sm text-muted-foreground">Последний бекап каждого сервера и базы данных</p>
      </div>
      <div v-if="data" class="flex gap-2">
        <button :class="btnClass.outline" class="h-8! px-3!" @click="collapsed = []">Развернуть всё</button>
        <button :class="btnClass.outline" class="h-8! px-3!" @click="collapsed = [...allKeys]">Свернуть всё</button>
      </div>
    </div>

    <div v-if="data" class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
      <div :class="card" class="p-4"><div class="text-xs text-muted-foreground">Всего</div><div class="text-2xl font-semibold">{{ stats.count }}</div></div>
      <div :class="card" class="p-4"><div class="text-xs text-muted-foreground">Успешно</div><div class="text-2xl font-semibold text-emerald-600">{{ stats.ok }}</div></div>
      <div :class="card" class="p-4"><div class="text-xs text-muted-foreground">С ошибкой</div><div class="text-2xl font-semibold" :class="stats.failed > 0 && 'text-destructive'">{{ stats.failed }}</div></div>
      <div :class="card" class="p-4"><div class="text-xs text-muted-foreground">Нет бекапов</div><div class="text-2xl font-semibold" :class="stats.none > 0 && 'text-destructive'">{{ stats.none }}</div></div>
      <div :class="card" class="p-4" title="Все хранящиеся удачные бекапы"><div class="text-xs text-muted-foreground">Размер всех бекапов</div><div class="text-2xl font-semibold">{{ formatBytes(stats.total) }}</div></div>
      <div :class="card" class="p-4" title="Сумма последних бекапов каждого объекта"><div class="text-xs text-muted-foreground">Размер последних</div><div class="text-2xl font-semibold">{{ formatBytes(stats.latest) }}</div></div>
    </div>

    <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    <p v-else-if="!data" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="size-4 animate-spin" />Загрузка…</p>
    <p v-else-if="!tree.length" class="text-sm text-muted-foreground">Серверов и баз данных пока нет.</p>

    <section v-for="g in tree" :key="g.key" :class="card" class="overflow-hidden">
      <button class="flex w-full items-center gap-2 px-4 py-3 text-left hover:bg-accent/40" @click="toggle(g.key)">
        <Icon name="chevron-right" class="size-4 shrink-0 transition-transform" :class="isOpen(g.key) && 'rotate-90'" />
        <span class="font-semibold">{{ g.name }}</span>
        <span class="text-xs text-muted-foreground">{{ g.count }}</span>
        <span class="ml-auto hidden text-xs text-muted-foreground tabular-nums sm:inline" title="Последние бекапы / все бекапы">последние {{ formatBytes(g.latest) }} · всего {{ formatBytes(g.total) }}</span>
        <span v-if="g.problems" class="rounded-full max-sm:ml-auto bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive">проблем: {{ g.problems }}</span>
      </button>

      <div v-if="isOpen(g.key)" class="border-t">
        <div v-for="p in g.projects" :key="p.key" class="border-b last:border-b-0">
          <div class="flex items-center gap-2 bg-muted/30 py-2 pr-4 pl-8">
            <button class="flex min-w-0 flex-1 items-center gap-2 text-left" @click="toggle(p.key)">
              <Icon name="chevron-right" class="size-4 shrink-0 transition-transform" :class="isOpen(p.key) && 'rotate-90'" />
              <span class="truncate font-medium">{{ p.name }}</span>
              <span class="text-xs text-muted-foreground">{{ p.count }}</span>
            </button>
            <span class="hidden text-xs text-muted-foreground tabular-nums sm:inline" title="Последние бекапы / все бекапы">последние {{ formatBytes(p.latest) }} · всего {{ formatBytes(p.total) }}</span>
            <span v-if="p.problems" class="rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive">проблем: {{ p.problems }}</span>
            <RouterLink v-if="p.id" :to="{ name: 'project', params: { id: p.id } }" class="text-xs text-primary hover:underline">Проект</RouterLink>
          </div>

          <table v-if="isOpen(p.key)" class="w-full table-fixed text-sm">
            <tbody>
              <template v-for="n in p.servers" :key="n.key">
                <template v-for="(row, i) in [...(n.server ? [{ kind: 'server' as const, s: n.server }] : []), ...(n.server && !isOpen(n.key) ? [] : n.databases.map((db) => ({ kind: 'database' as const, s: db })))]" :key="`${row.kind}:${row.s.id}`">
                  <tr class="border-t hover:bg-accent/30">
                    <td class="py-2 pr-3" :class="row.kind === 'database' && n.server ? 'pl-20' : 'pl-14'">
                      <div class="flex items-center gap-2">
                        <button
                          v-if="row.kind === 'server' && n.databases.length"
                          class="-ml-6 inline-flex size-5 items-center justify-center rounded hover:bg-accent"
                          :title="isOpen(n.key) ? 'Свернуть базы' : 'Показать базы'"
                          @click="toggle(n.key)"
                        >
                          <Icon name="chevron-right" class="size-3.5 transition-transform" :class="isOpen(n.key) && 'rotate-90'" />
                        </button>
                        <Icon :name="row.kind === 'server' ? 'server' : 'database'" class="size-4 shrink-0 text-primary" />
                        <RouterLink :to="{ name: row.kind, params: { id: row.s.id } }" class="truncate font-medium hover:underline">{{ row.s.name }}</RouterLink>
                        <span class="hidden truncate text-xs text-muted-foreground sm:inline">
                          {{ 'host' in row.s ? row.s.host : ENGINE_LABELS[row.s.engine as Engine] ?? row.s.engine }}
                        </span>
                        <span v-if="row.kind === 'server' && n.databases.length && !isOpen(n.key)" class="text-xs text-muted-foreground">+ баз: {{ n.databases.length }}</span>
                      </div>
                    </td>
                    <td class="w-40 py-2 pr-3">
                      <RouterLink v-if="row.s.latest" :to="{ name: 'backup', params: { id: row.s.latest.id } }" class="inline-flex items-center gap-1.5 hover:underline">
                        <span class="size-2 rounded-full" :class="STATUS[row.s.latest.status].dot" />{{ STATUS[row.s.latest.status].label }}
                      </RouterLink>
                      <span v-else class="font-semibold text-destructive">НЕТ</span>
                    </td>
                    <td class="hidden w-56 py-2 pr-3 md:table-cell">
                      <template v-if="row.s.latest">
                        <div>{{ formatDate(row.s.latest.finished_at ?? row.s.latest.started_at ?? row.s.latest.created_at) }}</div>
                        <div class="text-xs text-muted-foreground">{{ ago(row.s.latest.finished_at ?? row.s.latest.created_at) }}</div>
                      </template>
                    </td>
                    <td class="hidden w-36 py-2 pr-3 text-right tabular-nums lg:table-cell">
                      <div>{{ row.s.latest?.size ? formatBytes(row.s.latest.size) : '' }}</div>
                      <div v-if="row.s.total_count" class="text-xs text-muted-foreground" title="Все хранящиеся удачные бекапы">всего {{ formatBytes(row.s.total_size) }} · {{ row.s.total_count }} шт.</div>
                    </td>
                    <td class="hidden w-64 py-2 pr-4 text-xs text-muted-foreground xl:table-cell">
                      <span v-if="row.s.latest?.status === 'failed'" class="line-clamp-2 text-destructive" :title="row.s.latest.error ?? ''">{{ row.s.latest.error }}</span>
                      <span v-if="row.s.latest?.status === 'failed'" class="block">
                        {{ row.s.last_success_at ? `Удачный: ${formatDate(row.s.last_success_at)}` : 'Удачных не было' }}
                      </span>
                      <span v-else-if="!row.s.schedule_enabled">Без расписания</span>
                    </td>
                  </tr>
                </template>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</template>
