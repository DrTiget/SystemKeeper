<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { Discovery } from '@/api/servers'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { btnClass, formatBytes } from '@/lib/ui'

// Автопоиск: популярные каталоги, исключения и СУБД на сервере; выбранное добавляется в форму (сохранить — кнопкой формы)
const props = defineProps<{ serverId: number; paths: string[]; excludes: string[] }>()
const emit = defineEmits<{ close: []; apply: [result: { paths: string[]; excludes: string[] }] }>()

const data = ref<Discovery | null>(null)
const error = ref('')
const pickedPaths = ref<string[]>([])
const pickedExcludes = ref<string[]>([])

const CATEGORY: Record<string, string> = {
  system: 'Система',
  apps: 'Приложения',
  web: 'Сайты',
  docker: 'Docker',
  docker_volumes: 'Docker',
  mail: 'Почта',
}
const ENGINE: Record<string, string> = { mysql: 'MySQL / MariaDB', postgres: 'PostgreSQL', clickhouse: 'ClickHouse', mongodb: 'MongoDB', redis: 'Redis' }

/** Путь уже входит в выбранные каталоги (в форме или отмеченные здесь) */
const coveredBy = (path: string, list: string[]) => list.find((p) => p === '/' || p === path || path.startsWith(p + '/'))
const inForm = (path: string) => coveredBy(path, props.paths)
const coveredHere = (path: string) => coveredBy(path, pickedPaths.value.filter((p) => p !== path))

async function load() {
  error.value = ''
  data.value = null
  try {
    const d = (await api<{ data: Discovery }>(`/servers/${props.serverId}/discover`, { method: 'POST' })).data
    data.value = d
    pickedPaths.value = d.paths.filter((p) => p.recommended && !p.inside && !inForm(p.path)).map((p) => p.path)
    pickedExcludes.value = d.excludes.map((x) => x.pattern).filter((x) => !props.excludes.includes(x))
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Не удалось осмотреть сервер'
  }
}

const total = computed(() => (data.value?.paths ?? []).filter((p) => pickedPaths.value.includes(p.path) && !coveredHere(p.path)).reduce((n, p) => n + (p.size ?? 0), 0))

function apply() {
  emit('apply', { paths: pickedPaths.value, excludes: pickedExcludes.value })
}

onMounted(load)
</script>

<template>
  <Modal title="Что бекапить на сервере" wide @close="emit('close')">
    <div class="grid gap-5 text-sm">
      <p v-if="!data && !error" class="flex items-center gap-2 text-muted-foreground">
        <Icon name="loader" class="size-4 animate-spin" />Осматриваю сервер: каталоги, сайты, Docker, СУБД… Обычно до минуты.
      </p>
      <div v-if="error" class="grid gap-2">
        <p class="text-destructive">{{ error }}</p>
        <button :class="btnClass.outline" class="justify-self-start" @click="load"><Icon name="refresh" />Повторить</button>
      </div>

      <template v-if="data">
        <p class="text-xs text-muted-foreground">
          {{ [data.info.hostname, data.info.os, data.info.user && `вход как ${data.info.user}`, data.info.docker && `Docker ${data.info.docker}`].filter(Boolean).join(' · ') }}
          <template v-if="data.info.user && data.info.user !== 'root'"><br />Не под root часть каталогов могла быть недоступна.</template>
        </p>

        <section class="grid gap-2">
          <h4 class="font-semibold">Каталоги</h4>
          <p v-if="!data.paths.length" class="text-muted-foreground">Ничего типового не нашлось — выберите каталоги вручную.</p>
          <ul v-else class="divide-y rounded-lg border">
            <li v-for="p in data.paths" :key="p.path">
              <label class="flex cursor-pointer items-start gap-3 py-2 pr-3" :class="[p.inside ? 'pl-9' : 'pl-3', inForm(p.path) && 'cursor-default opacity-60']">
                <input
                  v-model="pickedPaths"
                  type="checkbox"
                  :value="p.path"
                  :disabled="!!inForm(p.path)"
                  class="mt-0.5 size-4 shrink-0 accent-primary"
                />
                <span class="grid min-w-0 flex-1 gap-0.5">
                  <span class="flex flex-wrap items-center gap-x-2">
                    <span class="font-mono text-xs font-medium break-all">{{ p.path }}</span>
                    <span class="rounded bg-muted px-1.5 text-[0.7rem] text-muted-foreground">{{ CATEGORY[p.category] ?? p.category }}</span>
                  </span>
                  <span class="text-xs text-muted-foreground">{{ p.reasons.join('; ') }}</span>
                  <span v-if="inForm(p.path)" class="text-xs text-muted-foreground">Уже в бекапе</span>
                  <span v-else-if="p.inside && coveredHere(p.path)" class="text-xs text-muted-foreground">Входит в {{ p.inside }}</span>
                  <span v-else-if="!p.recommended" class="text-xs text-amber-600">Не отмечено: внутри обычно файлы баз — их надёжнее бекапить дампом</span>
                </span>
                <span class="shrink-0 text-xs text-muted-foreground tabular-nums">{{ p.size !== null ? formatBytes(p.size) : p.inside ? '' : '—' }}</span>
              </label>
            </li>
          </ul>
          <p v-if="pickedPaths.length" class="text-xs text-muted-foreground">Отмечено примерно на {{ formatBytes(total) }} до сжатия.</p>
        </section>

        <section v-if="data.excludes.length" class="grid gap-2">
          <h4 class="font-semibold">Исключить</h4>
          <div class="grid gap-1">
            <label v-for="x in data.excludes" :key="x.pattern" class="flex cursor-pointer items-start gap-3" :class="excludes.includes(x.pattern) && 'cursor-default opacity-60'">
              <input v-model="pickedExcludes" type="checkbox" :value="x.pattern" :disabled="excludes.includes(x.pattern)" class="mt-0.5 size-4 shrink-0 accent-primary" />
              <span><span class="font-mono text-xs">{{ x.pattern }}</span> <span class="text-xs text-muted-foreground">— {{ excludes.includes(x.pattern) ? 'уже исключено' : x.reason }}</span></span>
            </label>
          </div>
        </section>

        <section v-if="data.databases.length" class="grid gap-2">
          <h4 class="font-semibold">Базы данных</h4>
          <p class="text-xs text-muted-foreground">Файлы работающей СУБД в архив не годятся — базы бекапятся дампом. Добавьте их в блоке «Базы данных» на странице сервера.</p>
          <ul class="divide-y rounded-lg border">
            <li v-for="d in data.databases" :key="d.engine + (d.container ?? '')" class="flex items-start gap-3 px-3 py-2">
              <Icon name="database" class="mt-0.5 size-4 shrink-0 text-primary" />
              <span class="grid min-w-0 flex-1 gap-0.5">
                <span class="font-medium">{{ ENGINE[d.engine] ?? d.engine }} <span class="font-normal text-muted-foreground">· {{ d.container ? `контейнер ${d.container}` : 'на сервере' }}</span></span>
                <span class="truncate text-xs text-muted-foreground" :title="d.detail">{{ d.detail }}</span>
              </span>
              <span v-if="d.added" class="shrink-0 text-xs text-emerald-600">Уже добавлена</span>
              <span v-else-if="d.supported" class="shrink-0 text-xs text-amber-600">Не добавлена</span>
              <span v-else class="shrink-0 text-xs text-muted-foreground">Дамп пока не поддерживается</span>
            </li>
          </ul>
        </section>

        <div class="flex justify-end gap-2">
          <button :class="btnClass.outline" @click="emit('close')">Отмена</button>
          <button :class="btnClass.primary" :disabled="!pickedPaths.length && !pickedExcludes.length" @click="apply">Добавить выбранное</button>
        </div>
      </template>
    </div>
  </Modal>
</template>
