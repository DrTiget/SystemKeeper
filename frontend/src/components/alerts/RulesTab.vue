<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import type { AlertChannel, AlertRule, AlertTargetType, Paginated } from '@/api/types'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import Pagination from '@/components/Pagination.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { CHANNEL_TYPES, EVENTS, TARGET_TYPES, eventLabel } from '@/lib/alerts'
import { btnClass, errorMessage, inputClass } from '@/lib/format'

const items = ref<AlertRule[]>([])
const meta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 })
const loading = ref(true)
const loadError = ref('')
const channels = ref<AlertChannel[]>([])
const names = ref<Partial<Record<AlertTargetType, Record<number, string>>>>({})

async function loadList(type: AlertTargetType) {
  if (names.value[type]) return names.value[type]!
  const map: Record<number, string> = {}
  try {
    const res = await api<Paginated<Record<string, unknown> & { id: number }>>(`${TARGET_TYPES[type].list}?per_page=100`)
    for (const x of res.data) map[x.id] = String(x[TARGET_TYPES[type].field] ?? `#${x.id}`)
  } catch {
    /* список недоступен — покажем #id */
  }
  names.value = { ...names.value, [type]: map }
  return map
}

async function load(page = meta.value.current_page) {
  loading.value = true
  loadError.value = ''
  try {
    const res = await api<Paginated<AlertRule>>(`/alert-rules?page=${page}&per_page=15`)
    if (!res.data.length && page > res.meta.last_page && res.meta.last_page > 0) return load(res.meta.last_page)
    items.value = res.data
    meta.value = res.meta
    const types = new Set(res.data.map((r) => r.target_type).filter((t): t is AlertTargetType => !!t))
    await Promise.all([...types].map(loadList))
  } catch (e) {
    loadError.value = errorMessage(e, 'Не удалось загрузить правила')
  } finally {
    loading.value = false
  }
}
onMounted(async () => {
  load(1)
  try {
    channels.value = (await api<Paginated<AlertChannel>>('/alert-channels?per_page=100')).data
  } catch {
    /* каналы подгрузятся при открытии формы нельзя — список останется пустым */
  }
})

const targetText = (r: AlertRule) =>
  !r.target_type ? 'Все объекты' : `${TARGET_TYPES[r.target_type].label}: ${names.value[r.target_type]?.[r.target_id!] ?? `#${r.target_id}`}`

// --- форма ---
type Form = { name: string; event: string; scope: '' | AlertTargetType; target_id: number | ''; channel_ids: number[]; quiet: boolean; qs: string; qe: string; throttle: number; enabled: boolean }
const editing = ref<'new' | AlertRule | null>(null)
const form = ref<Form>({ name: '', event: 'incident.opened', scope: '', target_id: '', channel_ids: [], quiet: false, qs: '22:00', qe: '08:00', throttle: 15, enabled: true })
const saving = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

function openForm(r: 'new' | AlertRule) {
  fieldErrors.value = {}
  formError.value = ''
  form.value =
    r === 'new'
      ? { name: '', event: 'incident.opened', scope: '', target_id: '', channel_ids: [], quiet: false, qs: '22:00', qe: '08:00', throttle: 15, enabled: true }
      : { name: r.name, event: r.event, scope: r.target_type ?? '', target_id: r.target_id ?? '', channel_ids: [...r.channel_ids], quiet: !!(r.quiet_hours_start && r.quiet_hours_end), qs: r.quiet_hours_start?.slice(0, 5) ?? '22:00', qe: r.quiet_hours_end?.slice(0, 5) ?? '08:00', throttle: r.throttle_minutes, enabled: r.enabled }
  editing.value = r
  if (form.value.scope) loadList(form.value.scope)
}
watch(
  () => form.value.scope,
  (s, old) => {
    if (old !== undefined && editing.value) form.value.target_id = ''
    if (s) loadList(s)
  },
)

function toggleChannel(id: number) {
  const ids = form.value.channel_ids
  form.value.channel_ids = ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]
}

async function save() {
  const f = form.value
  fieldErrors.value = {}
  formError.value = ''
  if (!f.channel_ids.length) return void (fieldErrors.value = { channel_ids: ['Выберите хотя бы один канал'] })
  if (f.scope && f.target_id === '') return void (fieldErrors.value = { target_id: ['Выберите объект'] })
  saving.value = true
  const body = {
    name: f.name,
    event: f.event,
    channel_ids: f.channel_ids,
    target_type: f.scope || null,
    target_id: f.scope ? f.target_id : null,
    quiet_hours_start: f.quiet ? f.qs : null,
    quiet_hours_end: f.quiet ? f.qe : null,
    throttle_minutes: f.throttle,
    enabled: f.enabled,
  }
  try {
    const t = editing.value
    if (t === 'new') await api('/alert-rules', { method: 'POST', body })
    else if (t) await api(`/alert-rules/${t.id}`, { method: 'PUT', body })
    editing.value = null
    await load(t === 'new' ? 1 : meta.value.current_page)
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      fieldErrors.value = e.errors
      formError.value = Object.keys(e.errors).length ? '' : errorMessage(e)
    } else formError.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}

// --- вкл/выкл ---
const toggleError = ref('')
async function toggle(r: AlertRule) {
  toggleError.value = ''
  try {
    const res = await api<{ data: AlertRule }>(`/alert-rules/${r.id}`, { method: 'PATCH', body: { enabled: !r.enabled } })
    const i = items.value.findIndex((x) => x.id === r.id)
    if (i >= 0) items.value[i] = res.data
  } catch (e) {
    toggleError.value = errorMessage(e, 'Не удалось изменить состояние')
  }
}

// --- удаление ---
const removing = ref<AlertRule | null>(null)
const removeLoading = ref(false)
const removeError = ref('')
async function remove() {
  if (!removing.value) return
  removeLoading.value = true
  removeError.value = ''
  try {
    await api(`/alert-rules/${removing.value.id}`, { method: 'DELETE' })
    removing.value = null
    await load()
  } catch (e) {
    removeError.value = errorMessage(e)
  } finally {
    removeLoading.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex justify-end"><button :class="btnClass.default" @click="openForm('new')"><Icon name="plus" />Добавить правило</button></div>
    <div v-if="toggleError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ toggleError }}</div>
    <div v-if="loadError" role="alert" class="flex items-center gap-3 rounded-lg border bg-card px-4 py-3 text-sm text-destructive">
      {{ loadError }}<button :class="[btnClass.outlineSm, 'ml-auto']" @click="load()">Повторить</button>
    </div>
    <div v-else-if="loading && !items.length" class="rounded-xl border p-10 text-center text-sm text-muted-foreground">Загрузка…</div>
    <div v-else-if="!items.length" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">Правил пока нет. Правило связывает событие с каналами.</div>
    <template v-else>
      <div class="overflow-x-auto rounded-xl border" :class="loading && 'opacity-60'">
        <table class="w-full text-sm">
          <thead class="border-b text-left text-xs text-muted-foreground">
            <tr>
              <th class="px-4 py-2 font-medium">Правило</th>
              <th class="px-4 py-2 font-medium">Объект</th>
              <th class="px-4 py-2 font-medium">Каналы</th>
              <th class="px-4 py-2 font-medium">Тихие часы</th>
              <th class="px-4 py-2 font-medium">Антиспам</th>
              <th class="px-4 py-2 font-medium">Состояние</th>
              <th class="px-4 py-2" />
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in items" :key="r.id" class="border-b last:border-0" :class="!r.enabled && 'opacity-70'">
              <td class="max-w-xs px-4 py-2"><span class="block truncate font-medium">{{ r.name }}</span><span class="block truncate text-xs text-muted-foreground">{{ eventLabel(r.event) }}</span></td>
              <td class="px-4 py-2">{{ targetText(r) }}</td>
              <td class="px-4 py-2"><span v-for="c in r.channels" :key="c.id" class="mr-1 inline-block rounded bg-muted px-1.5 py-0.5 text-xs" :title="CHANNEL_TYPES[c.type]">{{ c.name }}</span><span v-if="!r.channels.length" class="text-muted-foreground">—</span></td>
              <td class="px-4 py-2 whitespace-nowrap tabular-nums">{{ r.quiet_hours_start && r.quiet_hours_end ? `${r.quiet_hours_start.slice(0, 5)}–${r.quiet_hours_end.slice(0, 5)}` : '—' }}</td>
              <td class="px-4 py-2 whitespace-nowrap tabular-nums">{{ r.throttle_minutes ? `${r.throttle_minutes} мин` : 'выкл.' }}</td>
              <td class="px-4 py-2"><StatusBadge :tone="r.enabled ? 'success' : 'neutral'" :label="r.enabled ? 'Включено' : 'Выключено'" /></td>
              <td class="px-4 py-2 text-right whitespace-nowrap">
                <button :class="btnClass.ghostSm" @click="toggle(r)">{{ r.enabled ? 'Выключить' : 'Включить' }}</button>
                <button :class="btnClass.ghostSm" title="Редактировать" @click="openForm(r)"><Icon name="pencil" /></button>
                <button :class="btnClass.ghostSm" title="Удалить" @click="removeError = ''; removing = r"><Icon name="trash" /></button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="load" />
    </template>

    <Modal v-if="editing" wide :title="editing === 'new' ? 'Новое правило' : 'Редактировать правило'" @close="editing = null">
      <form class="grid gap-4" novalidate @submit.prevent="save">
        <div v-if="formError" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ formError }}</div>
        <div class="grid gap-2">
          <label for="r-name" class="text-sm leading-none font-medium">Название</label>
          <input id="r-name" v-model="form.name" required maxlength="255" :class="inputClass" :aria-invalid="!!fieldErrors.name" />
          <p v-for="m in fieldErrors.name" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid gap-2">
          <label for="r-event" class="text-sm leading-none font-medium">Событие</label>
          <select id="r-event" v-model="form.event" :class="inputClass"><option v-for="(label, k) in EVENTS" :key="k" :value="k">{{ label }}</option></select>
          <p v-for="m in fieldErrors.event" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="grid gap-2">
            <label for="r-scope" class="text-sm leading-none font-medium">Фильтр</label>
            <select id="r-scope" v-model="form.scope" :class="inputClass"><option value="">Все объекты</option><option v-for="(t, k) in TARGET_TYPES" :key="k" :value="k">{{ t.label }}</option></select>
            <p v-for="m in fieldErrors.target_type" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div v-if="form.scope" class="grid gap-2">
            <label for="r-target" class="text-sm leading-none font-medium">{{ TARGET_TYPES[form.scope].label }}</label>
            <select id="r-target" v-model="form.target_id" :class="inputClass" :aria-invalid="!!fieldErrors.target_id">
              <option value="" disabled>Выберите…</option>
              <option v-for="(n, id) in names[form.scope] ?? {}" :key="id" :value="Number(id)">{{ n }}</option>
            </select>
            <p v-for="m in fieldErrors.target_id" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
        </div>
        <div class="grid gap-2">
          <span class="text-sm leading-none font-medium">Каналы</span>
          <p v-if="!channels.length" class="text-sm text-muted-foreground">Каналов нет — сначала создайте их на вкладке «Каналы».</p>
          <label v-for="c in channels" :key="c.id" class="flex items-center gap-2 text-sm">
            <input type="checkbox" :checked="form.channel_ids.includes(c.id)" class="size-4 rounded-[4px] border-input accent-primary" @change="toggleChannel(c.id)" />
            {{ c.name }}<span class="text-xs text-muted-foreground">{{ CHANNEL_TYPES[c.type] }}{{ c.enabled ? '' : ' · выключен' }}</span>
          </label>
          <p v-for="m in [...(fieldErrors.channel_ids ?? []), ...(fieldErrors['channel_ids.0'] ?? [])]" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid gap-2">
          <label class="flex items-center gap-2 text-sm font-medium"><input v-model="form.quiet" type="checkbox" class="size-4 rounded-[4px] border-input accent-primary" />Тихие часы</label>
          <div v-if="form.quiet" class="flex items-center gap-2">
            <input v-model="form.qs" type="time" required :class="inputClass" class="w-32" aria-label="С" /><span class="text-sm text-muted-foreground">до</span>
            <input v-model="form.qe" type="time" required :class="inputClass" class="w-32" aria-label="До" />
          </div>
          <p class="text-xs text-muted-foreground">В это время (часовой пояс сервера) уведомления не отправляются, в журнале они помечаются как «Пропущено».</p>
          <p v-for="m in [...(fieldErrors.quiet_hours_start ?? []), ...(fieldErrors.quiet_hours_end ?? [])]" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid gap-2">
          <label for="r-thr" class="text-sm leading-none font-medium">Антиспам, минут</label>
          <input id="r-thr" v-model.number="form.throttle" type="number" min="0" max="10080" required :class="inputClass" class="w-40" :aria-invalid="!!fieldErrors.throttle_minutes" />
          <p class="text-xs text-muted-foreground">Не чаще одного раза за указанное время для одного объекта. 0 — без ограничения.</p>
          <p v-for="m in fieldErrors.throttle_minutes" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.enabled" type="checkbox" class="size-4 rounded-[4px] border-input accent-primary" />Включено</label>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" :class="btnClass.outline" @click="editing = null">Отмена</button>
          <button type="submit" :class="btnClass.default" :disabled="saving"><Icon v-if="saving" name="loader" class="animate-spin" />Сохранить</button>
        </div>
      </form>
    </Modal>

    <ConfirmDialog v-if="removing" title="Удалить правило?" :text="`Правило «${removing.name}» будет удалено. Журнал отправок сохранится.`" :loading="removeLoading" :error="removeError" @confirm="remove" @close="removing = null" />
  </div>
</template>
