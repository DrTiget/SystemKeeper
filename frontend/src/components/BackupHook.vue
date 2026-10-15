<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { BackupHook } from '@/api/backups'
import Icon from '@/components/Icon.vue'
import { btnClass, formatDate } from '@/lib/ui'

/** Ссылка-хук бекапа источника; source — «servers/5» или «databases/3» */
const props = defineProps<{ source: string }>()

const hook = ref<BackupHook | null>(null)
const busy = ref(false)
const error = ref('')
const copied = ref('')

const url = computed(() => (hook.value?.path ? location.origin + hook.value.path : ''))
const curl = computed(() => `curl -fsS -X POST ${url.value}`)

async function run(method: 'GET' | 'POST' | 'DELETE') {
  busy.value = true
  error.value = ''
  try {
    const res = await api<{ data: BackupHook } | undefined>(`/${props.source}/backup-hook`, { method })
    hook.value = res?.data ?? { enabled: false, path: null, last_used_at: null, created_at: null }
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Не удалось'
  } finally {
    busy.value = false
  }
}

function regenerate() {
  if (confirm('Выпустить новую ссылку? Старая перестанет работать — обновите её в CI/CD.')) run('POST')
}

function disable() {
  if (confirm('Выключить ссылку? Запуски по ней перестанут работать.')) run('DELETE')
}

async function copy(text: string, what: string) {
  try {
    await navigator.clipboard.writeText(text)
  } catch {
    // без HTTPS буфер обмена недоступен — копируем через выделение
    const el = Object.assign(document.createElement('textarea'), { value: text })
    document.body.append(el)
    el.select()
    document.execCommand('copy')
    el.remove()
  }
  copied.value = what
  setTimeout(() => (copied.value = ''), 1500)
}

onMounted(() => run('GET'))
</script>

<template>
  <div v-if="hook" class="grid gap-3">
    <div class="flex items-center justify-between gap-2">
      <h3 class="flex items-center gap-2 font-semibold"><Icon name="webhook" class="size-4 text-primary" />Запуск по ссылке</h3>
      <button v-if="!hook.enabled" :class="btnClass.outline" class="h-8! px-3!" :disabled="busy" @click="run('POST')"><Icon name="plus" />Включить</button>
    </div>

    <p v-if="!hook.enabled" class="text-sm text-muted-foreground">
      Секретная ссылка: POST на неё запускает бекап без входа — например, из CI/CD перед деплоем.
    </p>
    <template v-else>
      <div class="flex items-center gap-1 rounded-md border bg-muted/40 py-1 pr-1 pl-2">
        <code class="min-w-0 flex-1 truncate font-mono text-xs" :title="url">{{ url }}</code>
        <button class="inline-flex size-7 shrink-0 items-center justify-center rounded hover:bg-accent" title="Скопировать ссылку" @click="copy(url, 'url')">
          <Icon :name="copied === 'url' ? 'check' : 'copy'" class="size-3.5" />
        </button>
      </div>
      <div class="grid gap-1 text-xs text-muted-foreground">
        <span>Запуск из CI/CD:</span>
        <div class="flex items-center gap-1 rounded-md border bg-muted/40 py-1 pr-1 pl-2">
          <code class="min-w-0 flex-1 truncate font-mono" :title="curl">{{ curl }}</code>
          <button class="inline-flex size-7 shrink-0 items-center justify-center rounded hover:bg-accent" title="Скопировать команду" @click="copy(curl, 'curl')">
            <Icon :name="copied === 'curl' ? 'check' : 'copy'" class="size-3.5" />
          </button>
        </div>
        <span>
          Ответ 202 — бекап в очереди, его ход — по <code class="font-mono">status_url</code> из ответа; 409 — бекап уже идёт.
          Ссылка — как пароль: храните её в секретах CI.
        </span>
        <span>{{ hook.last_used_at ? `Последний запуск: ${formatDate(hook.last_used_at)}` : 'Ещё не запускалась' }}</span>
      </div>
      <div class="flex gap-2">
        <button :class="btnClass.outline" class="h-8! flex-1 px-3!" :disabled="busy" @click="regenerate"><Icon name="refresh" />Новая ссылка</button>
        <button :class="btnClass.destructive" class="h-8! flex-1 px-3!" :disabled="busy" @click="disable"><Icon name="x" />Выключить</button>
      </div>
    </template>
    <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
  </div>
</template>
