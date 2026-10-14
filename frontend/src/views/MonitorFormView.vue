<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import {
  defaultMonitorInput,
  type DnsRecordType,
  type HttpMethod,
  type Monitor,
  type MonitorCheckResult,
  type MonitorInput,
  MONITOR_STATUS,
  MONITOR_TYPES,
} from '@/api/monitors'
import Icon from '@/components/Icon.vue'
import ProjectSelect from '@/components/ProjectSelect.vue'
import SelectMenu from '@/components/SelectMenu.vue'
import SaveBar from '@/components/form/SaveBar.vue'
import SegmentedControl from '@/components/form/SegmentedControl.vue'
import SettingsSection from '@/components/form/SettingsSection.vue'
import ToggleSwitch from '@/components/form/ToggleSwitch.vue'
import { btnClass, inputClass } from '@/lib/ui'
import { unshownErrors, useUnsavedGuard } from '@/lib/unsaved'
import { useProjectsStore } from '@/stores/projects'

const route = useRoute()
const router = useRouter()
/** Редактирование — /monitors/:id/edit, создание — /monitors/new?project_id= */
const id = route.params.id ? Number(route.params.id) : null

const loading = ref(!!id)
const loadError = ref('')
const monitor = ref<Monitor | null>(null)
const form = reactive<MonitorInput>(defaultMonitorInput(route.query.project_id ? Number(route.query.project_id) : null))
const savedSnapshot = ref('')
const errors = ref<Record<string, string[]>>({})
const formError = ref('')
const saving = ref(false)
const savedNotice = ref(false)

const projects = useProjectsStore()

/** Переносим в форму только поля настроек (у Monitor есть ещё статус, история…) — копией, без общих массивов */
function fill(m: MonitorInput) {
  const defaults = defaultMonitorInput()
  const picked = Object.fromEntries(Object.keys(defaults).map((k) => [k, m[k as keyof MonitorInput] ?? defaults[k as keyof MonitorInput]]))
  picked.project_id = m.project_id ?? (m as Partial<Monitor>).project?.id ?? null
  Object.assign(form, JSON.parse(JSON.stringify(picked)))
  savedSnapshot.value = JSON.stringify(payload())
}

onMounted(async () => {
  if (!id) {
    savedSnapshot.value = JSON.stringify(payload())
    projects.crumbs = [{ label: 'Аптайм', to: '/monitors' }, { label: 'Новый монитор' }]
    return
  }
  try {
    const m = (await api<{ data: Monitor }>(`/monitors/${id}`)).data
    monitor.value = m
    fill(m)
    projects.crumbs = [
      ...(monitor.value.project ? [{ label: monitor.value.project.name, to: `/projects/${monitor.value.project.id}` }] : [{ label: 'Аптайм', to: '/monitors' }]),
      { label: monitor.value.name, to: `/monitors/${id}` },
      { label: 'Настройки' },
    ]
  } catch (e) {
    loadError.value = e instanceof ApiError && e.status === 404 ? 'Монитор не найден' : 'Не удалось загрузить монитор'
  } finally {
    loading.value = false
  }
})

/** Отправляем только поля выбранного типа — остальные сбрасываем, чтобы не хранить мусор */
function payload(): MonitorInput {
  const p = { ...form, headers: form.headers.filter((h) => h.name.trim()) }
  if (p.type !== 'http') p.url = null
  if (p.type === 'http') p.hostname = null
  if (p.type !== 'tcp') p.port = null
  if (!p.keyword?.trim()) p.keyword = null
  if (!p.body?.trim()) p.body = null
  if (!p.dns_resolver?.trim()) p.dns_resolver = null
  if (!p.dns_expected?.trim()) p.dns_expected = null
  return p
}
const dirty = computed(() => !loading.value && JSON.stringify(payload()) !== savedSnapshot.value)
// при создании уход со страницы предупреждает, только если что-то уже ввели
useUnsavedGuard(dirty)

/** Название по умолчанию — из адреса, пока пользователь не ввёл своё */
const suggestedName = computed(() => {
  if (form.type === 'http') {
    try {
      return new URL(form.url ?? '').hostname
    } catch {
      return ''
    }
  }
  return form.hostname?.trim() ?? ''
})

// Разовая проверка без сохранения
const testing = ref(false)
const testResult = ref<MonitorCheckResult | null>(null)
const testError = ref('')

async function test() {
  testing.value = true
  testResult.value = null
  testError.value = ''
  try {
    testResult.value = (await api<{ data: MonitorCheckResult }>('/monitors/test', { method: 'POST', body: payload() })).data
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) errors.value = e.errors
    testError.value = e instanceof ApiError ? e.message : 'Не удалось проверить'
  } finally {
    testing.value = false
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  formError.value = ''
  savedNotice.value = false
  const body = { ...payload(), name: form.name.trim() || suggestedName.value }
  try {
    const res = (await api<{ data: Monitor }>(id ? `/monitors/${id}` : '/monitors', { method: id ? 'PATCH' : 'POST', body })).data
    fill(res)
    savedNotice.value = true
    if (!id) router.replace({ name: 'monitor', params: { id: res.id } })
    else router.push({ name: 'monitor', params: { id } })
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      errors.value = e.errors
      formError.value = unshownErrors(e.errors, Object.keys(form))
    } else formError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

function reset() {
  if (monitor.value) fill(monitor.value)
  else fill(defaultMonitorInput(form.project_id))
}

// Коды ответа
const newStatus = ref('')
function addStatus() {
  const v = newStatus.value.trim()
  if (v && !form.accepted_statuses.includes(v)) form.accepted_statuses.push(v)
  newStatus.value = ''
}

// Интервал: пресеты или своё значение
const INTERVALS = [30, 60, 300, 900, 3600]
const intervalOptions = [
  ...INTERVALS.map((v) => ({ value: v, label: v < 60 ? `${v} с` : v < 3600 ? `${v / 60} мин` : '1 ч' })),
  { value: 0, label: 'Другой' },
]
const customInterval = ref(false)
const intervalPreset = computed({
  get: () => (customInterval.value || !INTERVALS.includes(form.interval) ? 0 : form.interval),
  set: (v: number) => {
    customInterval.value = v === 0
    if (v) form.interval = v
  },
})

const methods: HttpMethod[] = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']
const methodOptions = methods.map((m) => ({ value: m, label: m }))
const dnsTypes: DnsRecordType[] = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS']
const dnsOptions = dnsTypes.map((t) => ({ value: t, label: t }))
const keywordModes = [
  { value: false, label: 'есть на странице' },
  { value: true, label: 'нет на странице' },
]

const watchSsl = computed({
  get: () => form.ssl_expiry_days !== null,
  set: (v: boolean) => (form.ssl_expiry_days = v ? 14 : null),
})

const requestSummary = computed(() => {
  const parts = [form.method, form.follow_redirects ? 'с редиректами' : 'без редиректов']
  if (form.headers.filter((h) => h.name.trim()).length) parts.push(`заголовков: ${form.headers.filter((h) => h.name.trim()).length}`)
  if (form.body?.trim()) parts.push('с телом')
  return parts.join(' · ')
})

const failedChecks = (n: number) =>
  n % 10 === 1 && n % 100 !== 11 ? `${n} неудачная проверка` : [2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? `${n} неудачные проверки` : `${n} неудачных проверок`
const err = (k: string) => errors.value[k]
const field = 'grid gap-1.5'
const labelCls = 'text-sm font-medium'
const hint = 'text-xs text-muted-foreground'
</script>

<template>
  <div class="flex max-w-5xl flex-col gap-6 p-4">
    <RouterLink :to="id ? { name: 'monitor', params: { id } } : { name: 'monitors' }" class="inline-flex items-center gap-1.5 self-start text-sm text-muted-foreground hover:text-foreground">
      <Icon name="arrow-left" class="size-4" />{{ monitor?.name ?? 'Аптайм' }}
    </RouterLink>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>
    <p v-else-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>

    <template v-else>
      <header class="space-y-1">
        <h2 class="text-xl font-semibold tracking-tight">{{ id ? 'Настройки монитора' : 'Новый монитор' }}</h2>
        <p class="text-sm text-muted-foreground">Что проверять, как часто и когда считать, что сервис упал.</p>
      </header>

      <form class="rounded-xl border bg-card p-6 shadow-xs" @submit.prevent="save">
        <SettingsSection title="Что проверять" description="Тип проверки и адрес. Кнопка «Проверить» покажет результат до сохранения.">
          <div role="radiogroup" class="grid gap-2 sm:grid-cols-2">
            <label
              v-for="t in MONITOR_TYPES"
              :key="t.value"
              class="flex cursor-pointer gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-accent/50"
            >
              <input v-model="form.type" type="radio" :value="t.value" class="mt-0.5 size-4 accent-primary" />
              <span class="grid gap-0.5">
                <span class="text-sm font-medium">{{ t.label }}</span>
                <span class="text-xs text-muted-foreground">{{ t.hint }}</span>
              </span>
            </label>
          </div>

          <div v-if="form.type === 'http'" :class="field">
            <label for="m-url" :class="labelCls">Адрес</label>
            <input id="m-url" v-model="form.url" type="url" required placeholder="https://example.com/health" spellcheck="false" :class="inputClass" class="font-mono" :aria-invalid="!!err('url')" />
            <p v-for="m in err('url')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div v-else class="flex flex-wrap gap-3">
            <div :class="field" class="min-w-60 flex-1">
              <label for="m-host" :class="labelCls">{{ form.type === 'dns' ? 'Домен' : 'Хост или IP' }}</label>
              <input id="m-host" v-model="form.hostname" required :placeholder="form.type === 'dns' ? 'example.com' : 'db.example.com'" spellcheck="false" :class="inputClass" class="font-mono" :aria-invalid="!!err('hostname')" />
              <p v-for="m in err('hostname')" :key="m" class="text-sm text-destructive">{{ m }}</p>
            </div>
            <div v-if="form.type === 'tcp'" :class="field" class="w-28">
              <label for="m-port" :class="labelCls">Порт</label>
              <input id="m-port" v-model.number="form.port" type="number" min="1" max="65535" required placeholder="5432" :class="inputClass" :aria-invalid="!!err('port')" />
              <p v-for="m in err('port')" :key="m" class="text-sm text-destructive">{{ m }}</p>
            </div>
          </div>

          <template v-if="form.type === 'dns'">
            <div :class="field">
              <span :class="labelCls">Запись</span>
              <SegmentedControl v-model="form.dns_record_type" :options="dnsOptions" />
            </div>
            <div class="flex flex-wrap gap-3">
              <div :class="field" class="min-w-60 flex-1">
                <label for="m-dns-exp" :class="labelCls">Ожидаемое значение</label>
                <input id="m-dns-exp" v-model="form.dns_expected" placeholder="необязательно, например 93.184.216.34" spellcheck="false" :class="inputClass" class="font-mono" />
                <p :class="hint">Пусто — достаточно, что запись существует.</p>
              </div>
              <div :class="field" class="w-48">
                <label for="m-dns-res" :class="labelCls">DNS-сервер</label>
                <input id="m-dns-res" v-model="form.dns_resolver" placeholder="системный" spellcheck="false" :class="inputClass" class="font-mono" />
              </div>
            </div>
          </template>

          <div class="flex flex-wrap items-center gap-3">
            <button type="button" :class="btnClass.outline" :disabled="testing" @click="test"><Icon :name="testing ? 'loader' : 'refresh'" :class="testing && 'animate-spin'" />Проверить</button>
            <span v-if="testResult" class="flex flex-wrap items-center gap-x-1.5 text-sm">
              <span class="size-2 rounded-full" :class="MONITOR_STATUS[testResult.status].dot" />
              <span class="font-medium" :class="MONITOR_STATUS[testResult.status].text">{{ testResult.status === 'up' ? 'Работает' : 'Не прошла' }}</span>
              <span v-if="testResult.response_ms !== null" class="text-muted-foreground">· {{ testResult.response_ms }} мс</span>
              <span v-if="testResult.message" class="text-muted-foreground">· {{ testResult.message }}</span>
            </span>
            <span v-else-if="testError" class="text-sm text-destructive">{{ testError }}</span>
          </div>
        </SettingsSection>

        <SettingsSection title="Название и проект" description="Как монитор называется в списках и к какому проекту относится.">
          <div class="grid gap-3 sm:grid-cols-2">
            <div :class="field">
              <label for="m-name" :class="labelCls">Название</label>
              <input id="m-name" v-model="form.name" :placeholder="suggestedName || 'Сайт клиента'" :class="inputClass" :aria-invalid="!!err('name')" />
              <p v-for="m in err('name')" :key="m" class="text-sm text-destructive">{{ m }}</p>
            </div>
            <div :class="field">
              <label for="m-project" :class="labelCls">Проект</label>
              <ProjectSelect id="m-project" v-model="form.project_id" :invalid="!!err('project_id')" />
              <p v-for="m in err('project_id')" :key="m" class="text-sm text-destructive">{{ m }}</p>
            </div>
          </div>
        </SettingsSection>

        <SettingsSection v-if="form.type === 'http'" title="Когда считать, что работает" description="Код ответа и, если нужно, слово на странице — например, «ok» в ответе health-check.">
          <div :class="field">
            <span :class="labelCls">Коды ответа</span>
            <div class="flex flex-wrap items-center gap-1.5">
              <span v-for="s in form.accepted_statuses" :key="s" class="inline-flex items-center gap-1 rounded-md border bg-background py-1 pr-1 pl-2 font-mono text-xs">
                {{ s }}
                <button type="button" class="rounded hover:bg-accent" title="Убрать" @click="form.accepted_statuses = form.accepted_statuses.filter((x) => x !== s)"><Icon name="x" class="size-3.5" /></button>
              </span>
              <input v-model="newStatus" placeholder="301 или 400-499" aria-label="Код ответа" spellcheck="false" :class="inputClass" class="h-7! w-36! font-mono text-xs!" @keydown.enter.prevent="addStatus" @blur="addStatus" />
            </div>
            <p v-for="m in err('accepted_statuses')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div :class="field">
            <label for="m-kw" :class="labelCls">Слово на странице</label>
            <div class="flex flex-wrap items-center gap-2">
              <input id="m-kw" v-model="form.keyword" placeholder="необязательно" spellcheck="false" :class="inputClass" class="max-w-xs" />
              <SegmentedControl v-if="form.keyword?.trim()" v-model="form.keyword_invert" :options="keywordModes" />
            </div>
            <p :class="hint">Ищется в теле ответа с учётом регистра.</p>
          </div>
        </SettingsSection>

        <SettingsSection title="Как часто" description="Повторные проверки отсекают случайные сбои сети: монитор упадёт, только если не прошло несколько проверок подряд.">
          <div :class="field">
            <span :class="labelCls">Проверять каждые</span>
            <div class="flex flex-wrap items-center gap-2">
              <SegmentedControl v-model="intervalPreset" :options="intervalOptions" />
              <span v-if="intervalPreset === 0" class="flex items-center gap-2 text-sm">
                <input v-model.number="form.interval" type="number" min="20" max="86400" required aria-label="Интервал, секунд" :class="inputClass" class="w-24!" /> секунд
              </span>
            </div>
            <p v-for="m in err('interval')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2 text-sm">
            Ждать ответ не дольше
            <input v-model.number="form.timeout" type="number" min="1" max="120" required aria-label="Тайм-аут, секунд" :class="inputClass" class="w-20!" :aria-invalid="!!err('timeout')" />
            секунд
          </div>
          <p v-for="m in err('timeout')" :key="m" class="text-sm text-destructive">{{ m }}</p>
          <div class="grid gap-1.5">
            <div class="flex flex-wrap items-center gap-2 text-sm">
              Считать упавшим после
              <input v-model.number="form.retries" type="number" min="0" max="10" required aria-label="Повторов" :class="inputClass" class="w-20!" />
              повторов, проверяя каждые
              <input v-model.number="form.retry_interval" type="number" min="20" max="3600" required aria-label="Интервал повтора, секунд" :class="inputClass" class="w-24!" :disabled="!form.retries" />
              секунд
            </div>
            <p :class="hint">{{ form.retries ? `Всего ${failedChecks(form.retries + 1)} подряд до статуса «Недоступен».` : 'Без повторов — недоступен после первой же неудачи.' }}</p>
            <p v-for="m in [...(err('retries') ?? []), ...(err('retry_interval') ?? [])]" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
        </SettingsSection>

        <SettingsSection v-if="form.type === 'http' && form.url?.startsWith('https')" title="Сертификат" description="Напомнит заранее, что HTTPS-сертификат скоро истечёт.">
          <ToggleSwitch v-model="watchSsl" label="Следить за сроком сертификата" />
          <div v-if="watchSsl" class="flex flex-wrap items-center gap-2 pl-12 text-sm">
            Предупредить за
            <input v-model.number="form.ssl_expiry_days" type="number" min="1" max="90" required aria-label="Дней до истечения" :class="inputClass" class="w-20!" />
            дней до истечения
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="form.ignore_tls" type="checkbox" class="size-4 accent-primary" />Не проверять сертификат (самоподписанный)
          </label>
        </SettingsSection>

        <SettingsSection
          v-if="form.type === 'http'"
          title="Запрос"
          description="Метод, заголовки и тело — например, для API с авторизацией."
          collapsible
          :summary="requestSummary"
          :force-open="!!(err('method') || err('headers') || err('body'))"
        >
          <div :class="field" class="w-40">
            <span :class="labelCls">Метод</span>
            <SelectMenu v-model="form.method" :options="methodOptions" />
          </div>
          <div :class="field">
            <span :class="labelCls">Заголовки</span>
            <div v-for="(h, i) in form.headers" :key="i" class="flex gap-2">
              <input v-model="h.name" placeholder="Authorization" aria-label="Заголовок" spellcheck="false" :class="inputClass" class="w-48! font-mono" />
              <input v-model="h.value" placeholder="Bearer …" aria-label="Значение" spellcheck="false" :class="inputClass" class="font-mono" />
              <button type="button" class="inline-flex size-9 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground" title="Убрать" @click="form.headers.splice(i, 1)"><Icon name="x" class="size-4" /></button>
            </div>
            <button type="button" class="inline-flex items-center gap-1.5 self-start text-sm font-medium text-primary hover:underline" @click="form.headers.push({ name: '', value: '' })"><Icon name="plus" class="size-4" />Добавить заголовок</button>
          </div>
          <div v-if="!['GET', 'HEAD', 'OPTIONS'].includes(form.method)" :class="field">
            <label for="m-body" :class="labelCls">Тело запроса</label>
            <textarea id="m-body" v-model="form.body" rows="4" spellcheck="false" placeholder='{"ping": true}' :class="inputClass" class="h-auto py-2 font-mono text-xs" />
          </div>
          <label class="flex items-center gap-2 text-sm"><input v-model="form.follow_redirects" type="checkbox" class="size-4 accent-primary" />Следовать редиректам</label>
        </SettingsSection>

        <SaveBar v-if="id" :dirty="dirty" :saving="saving" :saved="savedNotice" :error="formError" class="mt-6" @reset="reset" />
        <div v-else class="-mx-6 -mb-6 mt-6 flex flex-wrap items-center justify-end gap-3 rounded-b-xl border-t px-6 py-3">
          <span v-if="formError" class="min-w-0 flex-1 text-sm whitespace-pre-wrap text-destructive">{{ formError }}</span>
          <RouterLink :to="{ name: 'monitors' }" :class="btnClass.outline">Отмена</RouterLink>
          <button type="submit" :class="btnClass.primary" :disabled="saving"><Icon v-if="saving" name="loader" class="animate-spin" />Создать монитор</button>
        </div>
      </form>
    </template>
  </div>
</template>
