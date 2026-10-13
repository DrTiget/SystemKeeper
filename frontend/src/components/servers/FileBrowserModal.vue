<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { DirListing, FileEntry } from '@/api/servers'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { btnClass, formatBytes, inputClass } from '@/lib/ui'

const props = defineProps<{ serverId: number; selected: string[]; title?: string }>()
const emit = defineEmits<{ close: []; apply: [paths: string[]] }>()

const listing = ref<DirListing | null>(null)
const loading = ref(false)
const error = ref('')
const filter = ref('')
const showHidden = ref(false)
const goInput = ref('')
const picked = ref<string[]>([...props.selected])

const parentOf = (path: string) => path.replace(/\/[^/]+\/?$/, '') || '/'

async function open(path: string) {
  loading.value = true
  error.value = ''
  filter.value = ''
  try {
    listing.value = (await api<{ data: DirListing }>(`/servers/${props.serverId}/files?path=${encodeURIComponent(path)}`)).data
    goInput.value = listing.value.path
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Не удалось открыть каталог'
    if (!listing.value) goInput.value = path
  } finally {
    loading.value = false
  }
}

const crumbs = computed(() => {
  const path = listing.value?.path ?? '/'
  const parts = path.split('/').filter(Boolean)
  return [{ name: '/', path: '/' }, ...parts.map((name, i) => ({ name, path: '/' + parts.slice(0, i + 1).join('/') }))]
})

const entries = computed(() => {
  const q = filter.value.trim().toLowerCase()
  return (listing.value?.entries ?? []).filter((e) => (showHidden.value || !e.name.startsWith('.')) && (!q || e.name.toLowerCase().includes(q)))
})
const hiddenCount = computed(() => (listing.value?.entries ?? []).filter((e) => e.name.startsWith('.')).length)

const isPicked = (path: string) => picked.value.includes(path)
/** Уже входит в выбранный родительский каталог — отдельно выбирать не нужно. */
const coveredBy = (path: string) => picked.value.find((p) => p !== path && (p === '/' || path.startsWith(p + '/')))

function toggle(path: string) {
  picked.value = isPicked(path) ? picked.value.filter((p) => p !== path) : [...picked.value, path].sort()
}

function onRowClick(e: FileEntry) {
  if (e.type === 'dir') open(e.path)
  else toggle(e.path)
}

onMounted(() => {
  // Открываем родителя первого выбранного пути — чтобы сразу было видно, что выбрано
  const first = props.selected[0]
  open(first ? parentOf(first) : '/')
})

const iconBtn = 'inline-flex size-8 shrink-0 items-center justify-center rounded-md hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-40'
</script>

<template>
  <Modal wide :title="title ?? 'Выбор каталогов'" @close="emit('close')">
    <div class="grid gap-3">
      <form class="flex gap-2" @submit.prevent="open(goInput.trim() || '/')">
        <button type="button" :class="iconBtn" title="Вверх" :disabled="!listing || listing.path === '/'" @click="open(parentOf(listing!.path))">
          <Icon name="arrow-up" />
        </button>
        <input v-model="goInput" spellcheck="false" placeholder="/var/www" :class="inputClass" class="font-mono" />
        <button type="submit" :class="btnClass.outline">Перейти</button>
      </form>

      <nav class="flex flex-wrap items-center gap-0.5 text-sm">
        <template v-for="(c, i) in crumbs" :key="c.path">
          <span v-if="i > 1" class="text-muted-foreground">/</span>
          <button type="button" class="rounded px-1 font-mono text-xs hover:bg-accent" :class="i === crumbs.length - 1 && 'font-semibold'" @click="open(c.path)">{{ c.name }}</button>
        </template>
      </nav>

      <div class="flex items-center gap-2">
        <input v-model="filter" type="search" placeholder="Фильтр по имени" :class="inputClass" class="h-8 flex-1" />
        <label v-if="hiddenCount" class="flex items-center gap-1.5 text-sm whitespace-nowrap text-muted-foreground">
          <input v-model="showHidden" type="checkbox" class="size-4 accent-primary" />Скрытые ({{ hiddenCount }})
        </label>
      </div>

      <div class="h-80 overflow-y-auto rounded-md border">
        <div v-if="loading" class="flex h-full items-center justify-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</div>
        <div v-else-if="error" class="flex h-full items-center justify-center p-6 text-center text-sm text-destructive">{{ error }}</div>
        <ul v-else-if="listing" class="divide-y text-sm">
          <li v-if="listing.path !== '/'" class="flex items-center gap-2 px-2 py-1.5">
            <input type="checkbox" class="size-4 accent-primary" :checked="isPicked(listing.path)" :title="`Выбрать ${listing.path}`" @change="toggle(listing.path)" />
            <span class="text-muted-foreground">Весь каталог <span class="font-mono text-xs text-foreground">{{ listing.path }}</span></span>
          </li>
          <li
            v-for="e in entries"
            :key="e.path"
            class="flex cursor-pointer items-center gap-2 px-2 py-1.5 hover:bg-accent/60"
            :class="e.type === 'other' && 'opacity-50'"
            @click="onRowClick(e)"
          >
            <input
              type="checkbox"
              class="size-4 accent-primary"
              :checked="isPicked(e.path) || !!coveredBy(e.path)"
              :disabled="e.type === 'other' || !!coveredBy(e.path)"
              :title="coveredBy(e.path) ? `Уже входит в ${coveredBy(e.path)}` : 'Выбрать'"
              @click.stop
              @change="toggle(e.path)"
            />
            <Icon :name="e.type === 'dir' ? 'folder' : 'file'" class="size-4 shrink-0" :class="e.type === 'dir' ? 'text-primary' : 'text-muted-foreground'" />
            <span class="min-w-0 flex-1 truncate font-mono text-xs">{{ e.name }}</span>
            <span v-if="e.link" class="text-xs text-muted-foreground">ссылка</span>
            <span v-if="e.size !== null" class="text-xs whitespace-nowrap text-muted-foreground">{{ formatBytes(e.size) }}</span>
            <Icon v-if="e.type === 'dir'" name="chevron-right" class="size-4 shrink-0 text-muted-foreground" />
          </li>
          <li v-if="!entries.length" class="p-6 text-center text-muted-foreground">{{ filter ? 'Ничего не найдено' : 'Каталог пуст' }}</li>
          <li v-if="listing.truncated" class="p-2 text-center text-xs text-muted-foreground">Показаны не все элементы — каталог слишком большой. Уточните путь.</li>
        </ul>
      </div>

      <div class="grid gap-2">
        <span class="text-sm font-medium">Выбрано: {{ picked.length }}</span>
        <div v-if="picked.length" class="flex max-h-24 flex-wrap gap-1.5 overflow-y-auto">
          <span v-for="p in picked" :key="p" class="inline-flex items-center gap-1 rounded-md bg-accent py-0.5 pr-1 pl-2 font-mono text-xs text-accent-foreground">
            {{ p }}
            <button type="button" class="rounded hover:bg-background/60" title="Убрать" @click="toggle(p)"><Icon name="x" class="size-3.5" /></button>
          </span>
        </div>
      </div>

      <div class="flex justify-end gap-2">
        <button type="button" :class="btnClass.outline" @click="emit('close')">Отмена</button>
        <button type="button" :class="btnClass.primary" @click="emit('apply', picked)">Готово</button>
      </div>
    </div>
  </Modal>
</template>
