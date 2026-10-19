<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { type AlertChannel, type AlertRule, type AlertSuggestion, CHANNEL_TYPES, describeCondition, SEVERITY } from '@/api/alerts'
import { api, ApiError } from '@/api/client'
import Icon from '@/components/Icon.vue'
import { btnClass } from '@/lib/ui'

/**
 * «Рекомендуемые правила»: базовый набор алертов в один клик. Показывается, пока есть что предложить.
 * main — правил ещё нет: блок занимает страницу и не сворачивается. «Скрыть» запоминается в localStorage.
 */
const props = defineProps<{ main: boolean }>()
const emit = defineEmits<{ applied: [rules: AlertRule[]]; loaded: [count: number] }>()

const HIDDEN_KEY = 'systemkeeper_suggestions_hidden'

const suggestions = ref<AlertSuggestion[]>([])
const channels = ref<AlertChannel[]>([])
const loaded = ref(false)
const selectedKeys = ref<string[]>([])
const selectedChannels = ref<number[]>([])
const hidden = ref(localStorage.getItem(HIDDEN_KEY) === '1')
const applying = ref(false)
const error = ref('')

function setHidden(v: boolean) {
  hidden.value = v
  if (v) localStorage.setItem(HIDDEN_KEY, '1')
  else localStorage.removeItem(HIDDEN_KEY)
}

/** Предложения и каналы; новое выбирается целиком, прежние отметки сохраняются */
async function load() {
  try {
    const [s, c] = await Promise.all([
      api<{ data: AlertSuggestion[] }>('/alert-rules/suggestions'),
      api<{ data: AlertChannel[] }>('/alert-channels'),
    ])
    const known = new Set(suggestions.value.map((x) => x.key))
    const kept = selectedKeys.value.filter((k) => s.data.some((x) => x.key === k))
    selectedKeys.value = [...kept, ...s.data.filter((x) => !known.has(x.key)).map((x) => x.key)]
    suggestions.value = s.data
    channels.value = c.data
    const ids = new Set(c.data.map((x) => x.id))
    const keptChannels = selectedChannels.value.filter((id) => ids.has(id))
    selectedChannels.value = selectedChannels.value.length ? keptChannels : c.data.filter((x) => x.enabled).map((x) => x.id)
  } catch {
    suggestions.value = []
  } finally {
    loaded.value = true
    emit('loaded', suggestions.value.length)
  }
}
onMounted(load)

const collapsed = computed(() => !props.main && hidden.value)
const canApply = computed(() => selectedKeys.value.length > 0 && selectedChannels.value.length > 0 && !applying.value)
const allSelected = computed(() => selectedKeys.value.length === suggestions.value.length)
const toggleAll = () => (selectedKeys.value = allSelected.value ? [] : suggestions.value.map((s) => s.key))

const rules = (n: number) => `${n} ${n % 10 === 1 && n % 100 !== 11 ? 'правило' : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14) ? 'правила' : 'правил'}`

async function apply() {
  applying.value = true
  error.value = ''
  try {
    const created = (await api<{ data: AlertRule[] }>('/alert-rules/suggestions/apply', { method: 'POST', body: { keys: selectedKeys.value, channel_ids: selectedChannels.value } })).data
    emit('applied', created)
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? Object.values(e.errors).flat()[0] ?? e.message : 'Не удалось добавить правила'
    // набор мог измениться (правило добавили в другой вкладке) — обновляем список
    if (e instanceof ApiError && e.status === 422) await load()
  } finally {
    applying.value = false
  }
}
</script>

<template>
  <template v-if="loaded && suggestions.length">
    <button v-if="collapsed" type="button" class="inline-flex items-center gap-2 self-start rounded-lg border border-dashed px-3 py-2 text-sm text-muted-foreground hover:border-primary hover:text-foreground" @click="setHidden(false)">
      <Icon name="shield" class="size-4" />Рекомендуемые правила<span class="font-medium text-foreground">{{ suggestions.length }}</span>
    </button>

    <section v-else class="grid gap-4 rounded-xl border bg-card p-4" :class="main && 'border-dashed p-6'">
      <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="space-y-1">
          <h3 class="flex items-center gap-2 font-semibold"><Icon name="shield" class="size-4 text-primary" />Рекомендуемые правила</h3>
          <p class="text-sm text-muted-foreground">
            {{ main ? 'Правил пока нет. Вот разумный базовый набор для того, что уже есть в SystemKeeper, — пороги можно поменять после добавления.' : 'Чего ещё не хватает для базового покрытия. Правила действуют на все проекты, пороги можно поменять после добавления.' }}
          </p>
        </div>
        <div class="flex items-center gap-3 text-sm">
          <button type="button" class="font-medium text-primary hover:underline" @click="toggleAll">{{ allSelected ? 'Снять все' : 'Выбрать все' }}</button>
          <button v-if="!main" type="button" class="text-muted-foreground hover:text-foreground" @click="setHidden(true)">Скрыть</button>
        </div>
      </header>

      <ul class="grid gap-2 lg:grid-cols-2">
        <li v-for="s in suggestions" :key="s.key">
          <label class="flex h-full cursor-pointer items-start gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-accent/50">
            <input v-model="selectedKeys" type="checkbox" :value="s.key" class="mt-0.5 size-4 accent-primary" />
            <span class="grid min-w-0 gap-1">
              <span class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium">{{ describeCondition(s) }}</span>
                <span class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-xs font-medium" :class="SEVERITY[s.severity].badge">{{ SEVERITY[s.severity].label }}</span>
              </span>
              <span class="text-xs text-muted-foreground">{{ s.reason }}</span>
            </span>
          </label>
        </li>
      </ul>

      <div class="grid gap-2">
        <span class="text-sm font-medium">Куда сообщать</span>
        <div v-if="channels.length" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          <label v-for="c in channels" :key="c.id" class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-accent/50">
            <input v-model="selectedChannels" type="checkbox" :value="c.id" class="size-4 accent-primary" />
            <Icon :name="CHANNEL_TYPES[c.type].icon" class="size-4 text-muted-foreground" />
            <span class="grid min-w-0">
              <span class="truncate text-sm font-medium">{{ c.name }}</span>
              <span class="text-xs text-muted-foreground">{{ CHANNEL_TYPES[c.type].label }}<template v-if="!c.enabled"> · выключен</template></span>
            </span>
          </label>
        </div>
        <p v-else class="text-sm text-amber-600 dark:text-amber-400">
          Чтобы добавить правила, нужен хотя бы один канал уведомлений.
          <RouterLink :to="{ name: 'alert-channels' }" class="font-medium text-primary hover:underline">Создать канал</RouterLink>
        </p>
        <p v-if="channels.length && !selectedChannels.length" class="text-sm text-amber-600 dark:text-amber-400">Выберите хотя бы один канал.</p>
      </div>

      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
      <div>
        <button type="button" :class="btnClass.primary" :disabled="!canApply" @click="apply">
          <Icon :name="applying ? 'loader' : 'plus'" :class="applying && 'animate-spin'" />Добавить {{ rules(selectedKeys.length) }}
        </button>
      </div>
    </section>
  </template>
</template>
