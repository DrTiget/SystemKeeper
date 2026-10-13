<script setup lang="ts" generic="T extends string | number">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import Icon from '@/components/Icon.vue'

export interface SelectOption<V> {
  value: V
  label: string
  hint?: string
}

const props = withDefaults(
  defineProps<{
    options: SelectOption<T>[]
    id?: string
    placeholder?: string
    /** Поле поиска в списке; по умолчанию — если вариантов больше 10. */
    searchable?: boolean
    invalid?: boolean
  }>(),
  { placeholder: 'Выберите', searchable: undefined },
)
const model = defineModel<T>()

const root = ref<HTMLElement>()
const list = ref<HTMLElement>()
const searchInput = ref<HTMLInputElement>()
const open = ref(false)
const query = ref('')
const active = ref(0)

const withSearch = computed(() => props.searchable ?? props.options.length > 10)
const selected = computed(() => props.options.find((o) => o.value === model.value))
const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  return q ? props.options.filter((o) => `${o.label} ${o.hint ?? ''}`.toLowerCase().includes(q)) : props.options
})

watch(filtered, () => (active.value = 0))

function scrollToActive() {
  nextTick(() => list.value?.querySelector<HTMLElement>(`[data-index="${active.value}"]`)?.scrollIntoView({ block: 'nearest' }))
}

function onOutside(e: PointerEvent) {
  if (!root.value?.contains(e.target as Node)) close()
}

function show() {
  if (open.value) return
  open.value = true
  query.value = ''
  active.value = Math.max(0, props.options.findIndex((o) => o.value === model.value))
  document.addEventListener('pointerdown', onOutside)
  nextTick(() => searchInput.value?.focus())
  scrollToActive()
}

function close() {
  open.value = false
  document.removeEventListener('pointerdown', onOutside)
}

function pick(o: SelectOption<T>) {
  model.value = o.value
  close()
  root.value?.querySelector<HTMLElement>('button')?.focus()
}

function onKeydown(e: KeyboardEvent) {
  if (!open.value) {
    if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) {
      e.preventDefault()
      show()
    }
    return
  }
  if (e.key === 'Escape' || e.key === 'Tab') {
    if (e.key === 'Escape') e.preventDefault()
    close()
  } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault()
    const n = filtered.value.length
    if (n) active.value = (active.value + (e.key === 'ArrowDown' ? 1 : n - 1)) % n
    scrollToActive()
  } else if (e.key === 'Enter') {
    e.preventDefault()
    const o = filtered.value[active.value]
    if (o) pick(o)
  }
}

onBeforeUnmount(close)
</script>

<template>
  <div ref="root" class="relative" @keydown="onKeydown">
    <button
      :id="id"
      type="button"
      role="combobox"
      :aria-expanded="open"
      :aria-invalid="invalid || undefined"
      class="flex h-9 w-full items-center gap-2 rounded-md border border-input bg-transparent px-3 text-left text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
      :class="open && 'border-ring ring-[3px] ring-ring/50'"
      @click="open ? close() : show()"
    >
      <span class="min-w-0 flex-1 truncate" :class="!selected && 'text-muted-foreground'">{{ selected?.label ?? placeholder }}</span>
      <span v-if="selected?.hint" class="shrink-0 text-xs text-muted-foreground">{{ selected.hint }}</span>
      <Icon name="chevrons" class="size-4 shrink-0 text-muted-foreground" />
    </button>

    <div v-if="open" class="absolute inset-x-0 top-full z-50 mt-1 overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md">
      <div v-if="withSearch" class="flex items-center gap-2 border-b px-3">
        <Icon name="search" class="size-4 shrink-0 text-muted-foreground" />
        <input ref="searchInput" v-model="query" placeholder="Поиск…" class="h-9 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground" />
      </div>
      <ul ref="list" role="listbox" class="max-h-64 overflow-y-auto p-1">
        <li
          v-for="(o, i) in filtered"
          :key="o.value"
          :data-index="i"
          role="option"
          :aria-selected="o.value === model"
          class="flex cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-sm"
          :class="i === active && 'bg-accent text-accent-foreground'"
          @pointermove="active = i"
          @click="pick(o)"
        >
          <Icon name="check" class="size-4 shrink-0" :class="o.value === model ? 'opacity-100' : 'opacity-0'" />
          <span class="min-w-0 flex-1 truncate">{{ o.label }}</span>
          <span v-if="o.hint" class="shrink-0 text-xs text-muted-foreground">{{ o.hint }}</span>
        </li>
        <li v-if="!filtered.length" class="px-2 py-6 text-center text-sm text-muted-foreground">Ничего не найдено</li>
      </ul>
    </div>
  </div>
</template>
