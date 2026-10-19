<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ALERT_KINDS, type AlertKind, type AlertRule, CHANNEL_TYPES, describeCondition, OBJECT_LABELS, SEVERITY } from '@/api/alerts'
import { api, ApiError } from '@/api/client'
import Icon from '@/components/Icon.vue'
import AlertsTabs from '@/components/alerts/AlertsTabs.vue'
import SuggestedRules from '@/components/alerts/SuggestedRules.vue'
import { btnClass, formatDate } from '@/lib/ui'
import { useProjectsStore } from '@/stores/projects'

useProjectsStore().crumbs = []
const router = useRouter()

const items = ref<AlertRule[]>([])
const loaded = ref(false)
const loadError = ref('')
/** Сколько рекомендуемых правил можно добавить; null — ещё не знаем */
const suggestionsCount = ref<number | null>(null)

async function load() {
  try {
    items.value = (await api<{ data: AlertRule[] }>('/alert-rules')).data
  } catch {
    loadError.value = 'Не удалось загрузить правила'
  } finally {
    loaded.value = true
  }
}
onMounted(load)

async function toggle(r: AlertRule) {
  r.enabled = !r.enabled
  try {
    Object.assign(r, (await api<{ data: AlertRule }>(`/alert-rules/${r.id}`, { method: 'PATCH', body: { enabled: r.enabled } })).data)
  } catch (e) {
    r.enabled = !r.enabled
    loadError.value = e instanceof ApiError ? e.message : 'Не удалось изменить'
  }
}

/** Правила по группам: Серверы / Аптайм / Бекапы */
const groups = computed(() => {
  const order = ['Серверы', 'Аптайм', 'Бекапы'] as const
  return order.map((g) => ({ title: g, items: items.value.filter((r) => ALERT_KINDS[r.kind].group === g) })).filter((g) => g.items.length)
})

const scope = (r: AlertRule) => {
  const where = r.project ? `проект «${r.project.name}»` : 'все проекты'
  if (r.objects.length) return r.objects.map((o) => o.name).join(', ')
  return `${ALERT_KINDS[r.kind].objects.map((t) => OBJECT_LABELS[t].many).join(' и ')} — ${where}`
}

/** Быстрый старт: типовые правила */
const starters: AlertKind[] = ['monitor_down', 'server_unreachable', 'server_disk', 'server_cpu', 'backup_failed', 'backup_missing']
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div class="space-y-1">
        <h2 class="text-xl font-semibold tracking-tight">Алерты</h2>
        <p class="text-sm text-muted-foreground">Когда поднимать тревогу и кому сообщать.</p>
      </div>
      <RouterLink :to="{ name: 'alert-rule-new' }" :class="btnClass.primary"><Icon name="plus" />Новое правило</RouterLink>
    </header>
    <AlertsTabs />

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>
    <!-- Пока правил нет — главный экран; иначе — над списком -->
    <SuggestedRules v-if="loaded && !loadError" :main="!items.length" @applied="load" @loaded="suggestionsCount = $event" />
    <p v-if="!loaded" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>

    <template v-else-if="items.length">
      <section v-for="g in groups" :key="g.title" class="grid gap-3">
        <h3 class="font-semibold">{{ g.title }} <span class="font-normal text-muted-foreground">{{ g.items.length }}</span></h3>
        <div class="overflow-x-auto rounded-xl border bg-card">
          <table class="w-full table-fixed text-sm">
            <thead class="border-b text-left text-muted-foreground">
              <tr>
                <th class="w-14 px-4 py-3"><span class="sr-only">Включено</span></th>
                <th class="px-4 py-3 font-medium">Правило</th>
                <th class="hidden w-[28%] px-4 py-3 font-medium md:table-cell">Где</th>
                <th class="hidden w-40 px-4 py-3 font-medium lg:table-cell">Куда</th>
                <th class="w-44 px-4 py-3 font-medium">Сейчас</th>
                <th class="w-10 px-2" />
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="r in g.items" :key="r.id" class="cursor-pointer hover:bg-accent/40" :class="!r.enabled && 'text-muted-foreground'" @click="router.push({ name: 'alert-rule-edit', params: { id: r.id } })">
                <td class="px-4 py-3" @click.stop>
                  <button
                    type="button"
                    role="switch"
                    :aria-checked="r.enabled"
                    :title="r.enabled ? 'Выключить' : 'Включить'"
                    class="relative inline-flex h-5 w-9 rounded-full transition-colors after:absolute after:top-0.5 after:left-0.5 after:size-4 after:rounded-full after:bg-background after:shadow-xs after:transition-transform"
                    :class="r.enabled ? 'bg-primary after:translate-x-4' : 'bg-input'"
                    @click="toggle(r)"
                  />
                </td>
                <td class="px-4 py-3">
                  <div class="flex items-center gap-2">
                    <span class="size-2 shrink-0 rounded-full" :class="SEVERITY[r.severity].dot" :title="SEVERITY[r.severity].label" />
                    <span class="truncate font-medium" :class="r.enabled && 'text-foreground'">{{ r.name }}</span>
                  </div>
                  <div class="truncate pl-4 text-xs text-muted-foreground">{{ describeCondition(r) }} · {{ SEVERITY[r.severity].label.toLowerCase() }}</div>
                </td>
                <td class="hidden truncate px-4 py-3 text-muted-foreground md:table-cell" :title="scope(r)">{{ scope(r) }}</td>
                <td class="hidden px-4 py-3 lg:table-cell">
                  <span v-if="!r.channels.length" class="text-xs text-amber-600 dark:text-amber-400">никуда</span>
                  <span v-else class="flex items-center gap-1.5 text-muted-foreground" :title="r.channels.map((c) => c.name).join(', ')">
                    <Icon v-for="c in r.channels.slice(0, 4)" :key="c.id" :name="CHANNEL_TYPES[c.type].icon" class="size-4" />
                    <span class="truncate text-xs">{{ r.channels.length === 1 ? r.channels[0]!.name : `${r.channels.length} канала` }}</span>
                  </span>
                </td>
                <td class="px-4 py-3">
                  <RouterLink v-if="r.firing_count" to="/alerts" class="inline-flex items-center gap-1.5 font-medium whitespace-nowrap text-destructive hover:underline" @click.stop>
                    <span class="size-2 animate-pulse rounded-full bg-destructive" />{{ r.firing_count }} {{ r.firing_count === 1 ? 'срабатывание' : 'срабатываний' }}
                  </RouterLink>
                  <span v-else class="text-xs text-muted-foreground">{{ r.last_fired_at ? `было ${formatDate(r.last_fired_at)}` : 'не срабатывало' }}</span>
                </td>
                <td class="px-2 text-right text-muted-foreground"><Icon name="chevron-right" class="inline size-4" /></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <div v-else-if="!loadError && suggestionsCount === 0" class="grid gap-4 rounded-xl border border-dashed p-8">
      <div class="grid justify-items-center gap-1 text-center">
        <Icon name="bell" class="size-8 text-muted-foreground" />
        <p class="font-medium">Правил пока нет</p>
        <p class="max-w-md text-sm text-muted-foreground">Начните с типовых — пороги уже подобраны, их можно поменять.</p>
      </div>
      <div class="mx-auto grid w-full max-w-3xl gap-2 sm:grid-cols-2 lg:grid-cols-3">
        <RouterLink
          v-for="k in starters"
          :key="k"
          :to="{ name: 'alert-rule-new', query: { kind: k } }"
          class="grid gap-0.5 rounded-lg border bg-card p-3 hover:border-primary hover:bg-accent/50"
        >
          <span class="text-sm font-medium">{{ ALERT_KINDS[k].label }}</span>
          <span class="text-xs text-muted-foreground">{{ describeCondition({ kind: k, ...ALERT_KINDS[k].defaults }) }}</span>
        </RouterLink>
      </div>
    </div>
  </div>
</template>
