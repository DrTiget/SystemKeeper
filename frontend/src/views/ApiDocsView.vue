<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { getToken } from '@/api/client'
import { renderMarkdown } from '@/lib/markdown'
import { btnClass, inputClass } from '@/lib/ui'

/** Документация API: спецификация OpenAPI (/api/docs.json, генерирует Scramble) в виде страницы интерфейса. */

type Schema = Record<string, any>
interface Operation {
  method: string
  path: string
  summary?: string
  description?: string
  tags?: string[]
  parameters?: Schema[]
  requestBody?: Schema
  responses?: Record<string, Schema>
}

const spec = ref<Schema | null>(null)
const error = ref('')
const search = ref('')
const open = ref<string | null>(null)

async function load() {
  try {
    const res = await fetch('/api/docs.json', { headers: { Accept: 'application/json', Authorization: `Bearer ${getToken() ?? ''}` } })
    if (!res.ok) throw new Error(String(res.status))
    spec.value = await res.json()
  } catch {
    error.value = 'Не удалось загрузить спецификацию API'
  }
}

const operations = computed<Operation[]>(() => {
  const list: Operation[] = []
  for (const [path, methods] of Object.entries<Schema>(spec.value?.paths ?? {})) {
    for (const [method, op] of Object.entries<Schema>(methods)) {
      if (['get', 'post', 'put', 'patch', 'delete'].includes(method)) list.push({ ...op, method: method.toUpperCase(), path })
    }
  }
  return list
})

const groups = computed(() => {
  const q = search.value.trim().toLowerCase()
  const map = new Map<string, Operation[]>()
  for (const op of operations.value) {
    const text = `${op.method} ${op.path} ${op.summary ?? ''} ${op.description ?? ''}`.toLowerCase()
    if (q && !text.includes(q)) continue
    const tag = op.tags?.[0] ?? 'Прочее'
    if (!map.has(tag)) map.set(tag, [])
    map.get(tag)!.push(op)
  }
  return [...map.entries()]
})

function resolve(s: Schema | undefined): Schema {
  if (!s) return {}
  if (s.$ref) {
    const name = String(s.$ref).split('/').pop()!
    const section = String(s.$ref).includes('/responses/') ? 'responses' : String(s.$ref).includes('/requestBodies/') ? 'requestBodies' : 'schemas'
    return { ...(spec.value?.components?.[section]?.[name] ?? {}), _ref: name }
  }
  return s
}

/** Схема → компактная запись в духе TypeScript */
function schemaText(raw: Schema | undefined, depth = 0, seen: string[] = []): string {
  const s = resolve(raw)
  const pad = '  '.repeat(depth)
  if (s._ref && seen.includes(s._ref)) return s._ref
  const next = s._ref ? [...seen, s._ref] : seen
  if (s.anyOf || s.oneOf) return (s.anyOf ?? s.oneOf).map((x: Schema) => schemaText(x, depth, next)).join(' | ')
  if (s.allOf) return s.allOf.map((x: Schema) => schemaText(x, depth, next)).join(' & ')
  if (s.enum) return s.enum.map((v: unknown) => JSON.stringify(v)).join(' | ')
  const type = Array.isArray(s.type) ? s.type.filter((t: string) => t !== 'null')[0] : s.type
  const nullable = Array.isArray(s.type) && s.type.includes('null') ? ' | null' : ''
  if (type === 'array') return `${schemaText(s.items, depth, next)}[]${nullable}`
  if (type === 'object' || s.properties) {
    const props = Object.entries<Schema>(s.properties ?? {})
    if (!props.length || depth > 4) return 'object' + nullable
    const required: string[] = s.required ?? []
    const lines = props.map(([k, v]) => {
      const desc = resolve(v).description ? `  // ${String(resolve(v).description).split('\n')[0]}` : ''
      return `${pad}  ${k}${required.includes(k) ? '' : '?'}: ${schemaText(v, depth + 1, next)}${desc}`
    })
    return `{\n${lines.join('\n')}\n${pad}}${nullable}`
  }
  return (type ?? 'any') + (s.format ? `<${s.format}>` : '') + nullable
}

function bodySchema(body: Schema | undefined): Schema | undefined {
  const b = resolve(body)
  return b.content?.['application/json']?.schema ?? b.content?.[Object.keys(b.content ?? {})[0] ?? '']?.schema
}

const methodClass: Record<string, string> = {
  GET: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
  POST: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
  PUT: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
  PATCH: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
  DELETE: 'bg-red-500/15 text-red-700 dark:text-red-300',
}

function download() {
  const blob = new Blob([JSON.stringify(spec.value, null, 2)], { type: 'application/json' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = 'systemkeeper-openapi.json'
  a.click()
  URL.revokeObjectURL(a.href)
}

const base = `${location.origin}/api/v1`

onMounted(load)
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div class="space-y-0.5">
        <h2 class="text-xl font-semibold tracking-tight">Документация API</h2>
        <p class="text-sm text-muted-foreground">
          Базовый адрес <code class="font-mono">{{ base }}</code>
          <span v-if="spec"> · {{ operations.length }} методов · OpenAPI {{ spec.openapi }}</span>
        </p>
      </div>
      <div class="flex gap-2">
        <RouterLink to="/applications" :class="btnClass.outline">Приложения</RouterLink>
        <button v-if="spec" :class="btnClass.outline" @click="download">Скачать OpenAPI</button>
      </div>
    </header>

    <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

    <template v-if="spec">
      <section class="prose-sm max-w-3xl rounded-xl border bg-card p-4 text-sm [&_code]:font-mono [&_h2]:mt-3 [&_h2]:font-semibold [&_li]:ml-4 [&_li]:list-disc" v-html="renderMarkdown(spec.info?.description ?? '')" />

      <section class="max-w-3xl rounded-xl border bg-card p-4 text-sm">
        <h3 class="mb-2 font-semibold">Пример</h3>
        <pre class="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs">curl -H "Authorization: Bearer ska_…" -H "Accept: application/json" {{ base }}/servers

curl -X POST -H "X-Api-Key: ska_…" {{ base }}/servers/1/backups</pre>
      </section>

      <input v-model="search" type="search" placeholder="Поиск метода: путь, описание" :class="inputClass" class="max-w-sm" />

      <section v-for="[tag, ops] in groups" :key="tag" class="grid gap-2">
        <h3 class="text-base font-semibold">{{ tag }}</h3>
        <div class="divide-y overflow-hidden rounded-xl border bg-card">
          <div v-for="op in ops" :key="op.method + op.path">
            <button class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-accent/40" @click="open = open === op.method + op.path ? null : op.method + op.path">
              <span class="w-16 shrink-0 rounded px-1.5 py-0.5 text-center font-mono text-xs font-semibold" :class="methodClass[op.method]">{{ op.method }}</span>
              <code class="font-mono text-xs">{{ op.path }}</code>
              <span class="truncate text-muted-foreground">{{ op.summary }}</span>
            </button>
            <div v-if="open === op.method + op.path" class="grid gap-3 border-t bg-muted/30 px-4 py-3 text-sm">
              <div v-if="op.description" class="[&_code]:font-mono" v-html="renderMarkdown(op.description)" />
              <div v-if="op.parameters?.length">
                <h4 class="mb-1 font-medium">Параметры</h4>
                <table class="w-full text-xs">
                  <tbody class="divide-y">
                    <tr v-for="p in op.parameters.map(resolve)" :key="p.in + p.name">
                      <td class="py-1 pr-3 font-mono">{{ p.name }}<span v-if="p.required" class="text-destructive">*</span></td>
                      <td class="py-1 pr-3 text-muted-foreground">{{ p.in }}</td>
                      <td class="py-1 pr-3 font-mono">{{ schemaText(p.schema) }}</td>
                      <td class="py-1 text-muted-foreground">{{ p.description }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-if="bodySchema(op.requestBody)">
                <h4 class="mb-1 font-medium">Тело запроса</h4>
                <pre class="overflow-x-auto rounded-md border bg-background p-3 font-mono text-xs">{{ schemaText(bodySchema(op.requestBody)) }}</pre>
              </div>
              <div v-for="(r, code) in op.responses" :key="code">
                <h4 class="mb-1 font-medium">
                  Ответ {{ code }} <span class="font-normal text-muted-foreground">{{ resolve(r).description }}</span>
                </h4>
                <pre v-if="bodySchema(r)" class="overflow-x-auto rounded-md border bg-background p-3 font-mono text-xs">{{ schemaText(bodySchema(r)) }}</pre>
              </div>
            </div>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>
