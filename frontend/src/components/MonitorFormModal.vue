<script setup lang="ts">
import { ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { Monitor, MonitorType } from '@/api/types'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { TYPES } from '@/lib/monitoring'
import { btnClass, errorMessage, inputClass } from '@/lib/format'

const props = defineProps<{ monitor: Monitor | null }>()
const emit = defineEmits<{ saved: [m: Monitor]; close: [] }>()

const hints: Record<MonitorType, { label: string; placeholder: string } | null> = {
  http: { label: 'URL', placeholder: 'https://example.com/health' },
  keyword: { label: 'URL', placeholder: 'https://example.com' },
  tcp: { label: 'Хост:порт', placeholder: 'db.example.com:3306' },
  ping: { label: 'Хост или IP', placeholder: 'example.com' },
  ssl: { label: 'Хост, хост:порт или https://…', placeholder: 'example.com' },
  heartbeat: null,
}

const m = props.monitor
const form = ref({
  name: m?.name ?? '',
  type: (m?.type ?? 'http') as MonitorType,
  target: m?.target ?? '',
  interval_seconds: m?.interval_seconds ?? 60,
  timeout_seconds: m?.timeout_seconds ?? 10,
  expected_status: m?.expected_status ?? ('' as number | ''),
  keyword: m?.keyword ?? '',
  ssl_expiry_days: m?.ssl_expiry_days ?? 14,
  heartbeat_grace_seconds: m?.heartbeat_grace_seconds ?? 60,
  failure_threshold: m?.failure_threshold ?? 3,
  enabled: m?.enabled ?? true,
})
const saving = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

async function save() {
  saving.value = true
  formError.value = ''
  fieldErrors.value = {}
  const f = form.value
  const t = f.type
  const body: Record<string, unknown> = { name: f.name, failure_threshold: f.failure_threshold, enabled: f.enabled, interval_seconds: f.interval_seconds }
  if (!props.monitor) body.type = t
  if (t !== 'heartbeat') {
    body.target = f.target
    body.timeout_seconds = f.timeout_seconds
  }
  if (t === 'http' || t === 'keyword') body.expected_status = f.expected_status === '' ? null : f.expected_status
  if (t === 'keyword') body.keyword = f.keyword
  if (t === 'ssl') body.ssl_expiry_days = f.ssl_expiry_days
  if (t === 'heartbeat') body.heartbeat_grace_seconds = f.heartbeat_grace_seconds
  try {
    const res = props.monitor
      ? await api<{ data: Monitor }>(`/monitors/${props.monitor.id}`, { method: 'PUT', body })
      : await api<{ data: Monitor }>('/monitors', { method: 'POST', body })
    emit('saved', res.data)
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      fieldErrors.value = e.errors
      formError.value = Object.keys(e.errors).length ? '' : errorMessage(e)
    } else formError.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal wide :title="monitor ? 'Редактировать монитор' : 'Новый монитор'" @close="emit('close')">
    <form class="grid gap-4" @submit.prevent="save">
      <div v-if="formError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ formError }}</div>
      <div class="grid gap-2">
        <label for="m-name" class="text-sm leading-none font-medium">Название</label>
        <input id="m-name" v-model="form.name" required maxlength="255" :class="inputClass" :aria-invalid="!!fieldErrors.name" />
        <p v-for="e in fieldErrors.name" :key="e" class="text-sm text-destructive">{{ e }}</p>
      </div>
      <div class="grid gap-2">
        <span class="text-sm leading-none font-medium">Тип</span>
        <div v-if="!monitor" class="flex flex-wrap gap-2">
          <button v-for="(label, k) in TYPES" :key="k" type="button" :class="form.type === k ? btnClass.default : btnClass.outline" @click="form.type = k">{{ label }}</button>
        </div>
        <p v-else class="text-sm">{{ TYPES[form.type] }} <span class="text-muted-foreground">(тип менять нельзя)</span></p>
        <p v-for="e in fieldErrors.type" :key="e" class="text-sm text-destructive">{{ e }}</p>
      </div>
      <div v-if="hints[form.type]" class="grid gap-2">
        <label for="m-target" class="text-sm leading-none font-medium">{{ hints[form.type]!.label }}</label>
        <input id="m-target" v-model="form.target" required class="font-mono" :placeholder="hints[form.type]!.placeholder" :class="inputClass" :aria-invalid="!!fieldErrors.target" />
        <p v-for="e in fieldErrors.target" :key="e" class="text-sm text-destructive">{{ e }}</p>
      </div>
      <p v-else class="rounded-lg border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
        Для heartbeat цель не задаётся: после сохранения появится URL, который нужно регулярно дёргать (например, из cron).
      </p>
      <div v-if="form.type === 'keyword'" class="grid gap-2">
        <label for="m-kw" class="text-sm leading-none font-medium">Ключевое слово</label>
        <input id="m-kw" v-model="form.keyword" required :class="inputClass" :aria-invalid="!!fieldErrors.keyword" />
        <p class="text-xs text-muted-foreground">Должно присутствовать в теле ответа.</p>
        <p v-for="e in fieldErrors.keyword" :key="e" class="text-sm text-destructive">{{ e }}</p>
      </div>
      <div v-if="form.type === 'http' || form.type === 'keyword'" class="grid gap-2">
        <label for="m-status" class="text-sm leading-none font-medium">Ожидаемый HTTP-код</label>
        <input id="m-status" v-model.number="form.expected_status" type="number" min="100" max="599" placeholder="любой 2xx" :class="inputClass" :aria-invalid="!!fieldErrors.expected_status" />
        <p v-for="e in fieldErrors.expected_status" :key="e" class="text-sm text-destructive">{{ e }}</p>
      </div>
      <div v-if="form.type === 'ssl'" class="grid gap-2">
        <label for="m-ssl" class="text-sm leading-none font-medium">Предупреждать за, дней до истечения</label>
        <input id="m-ssl" v-model.number="form.ssl_expiry_days" type="number" min="1" max="365" required :class="inputClass" :aria-invalid="!!fieldErrors.ssl_expiry_days" />
        <p v-for="e in fieldErrors.ssl_expiry_days" :key="e" class="text-sm text-destructive">{{ e }}</p>
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="grid gap-2">
          <label for="m-int" class="text-sm leading-none font-medium">{{ form.type === 'heartbeat' ? 'Ожидаемый интервал пинга, с' : 'Интервал проверки, с' }}</label>
          <input id="m-int" v-model.number="form.interval_seconds" type="number" min="30" max="86400" required :class="inputClass" :aria-invalid="!!fieldErrors.interval_seconds" />
          <p v-for="e in fieldErrors.interval_seconds" :key="e" class="text-sm text-destructive">{{ e }}</p>
        </div>
        <div v-if="form.type === 'heartbeat'" class="grid gap-2">
          <label for="m-grace" class="text-sm leading-none font-medium">Допуск, с</label>
          <input id="m-grace" v-model.number="form.heartbeat_grace_seconds" type="number" min="0" required :class="inputClass" :aria-invalid="!!fieldErrors.heartbeat_grace_seconds" />
          <p v-for="e in fieldErrors.heartbeat_grace_seconds" :key="e" class="text-sm text-destructive">{{ e }}</p>
        </div>
        <div v-else class="grid gap-2">
          <label for="m-to" class="text-sm leading-none font-medium">Таймаут, с</label>
          <input id="m-to" v-model.number="form.timeout_seconds" type="number" min="1" max="60" required :class="inputClass" :aria-invalid="!!fieldErrors.timeout_seconds" />
          <p v-for="e in fieldErrors.timeout_seconds" :key="e" class="text-sm text-destructive">{{ e }}</p>
        </div>
        <div class="grid gap-2">
          <label for="m-thr" class="text-sm leading-none font-medium">Неудач подряд до инцидента</label>
          <input id="m-thr" v-model.number="form.failure_threshold" type="number" min="1" max="100" required :class="inputClass" :aria-invalid="!!fieldErrors.failure_threshold" />
          <p v-for="e in fieldErrors.failure_threshold" :key="e" class="text-sm text-destructive">{{ e }}</p>
        </div>
      </div>
      <label class="flex items-center gap-2 text-sm">
        <input v-model="form.enabled" type="checkbox" class="size-4 rounded-[4px] border-input accent-primary" />Включён
      </label>
      <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <button type="button" :class="btnClass.outline" @click="emit('close')">Отмена</button>
        <button type="submit" :class="btnClass.default" :disabled="saving"><Icon v-if="saving" name="loader" class="animate-spin" />Сохранить</button>
      </div>
    </form>
  </Modal>
</template>
