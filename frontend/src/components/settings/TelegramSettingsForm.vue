<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import { DEFAULT_TELEGRAM_API, type NotificationSettings, type ProxyType, type TestResult } from '@/api/settings'
import Icon from '@/components/Icon.vue'
import SaveBar from '@/components/form/SaveBar.vue'
import SegmentedControl from '@/components/form/SegmentedControl.vue'
import ToggleSwitch from '@/components/form/ToggleSwitch.vue'
import { btnClass, inputClass } from '@/lib/ui'
import { unshownErrors, useUnsavedGuard } from '@/lib/unsaved'

/** Карточка «Telegram»: адрес Bot API и прокси (с сервера в РФ api.telegram.org часто недоступен). */
const props = defineProps<{ settings: NotificationSettings['telegram'] }>()
const emit = defineEmits<{ saved: [settings: NotificationSettings] }>()

const form = reactive({
  api_url: '',
  proxy: { enabled: false, type: 'http' as ProxyType, host: '', port: '' as number | '', username: '', password: '', clear_password: false },
})
const errors = ref<Record<string, string[]>>({})
const formError = ref('')
const saving = ref(false)
const savedNotice = ref(false)
const snapshot = ref('')

function fill(s: NotificationSettings['telegram']) {
  form.api_url = s.api_url === DEFAULT_TELEGRAM_API ? '' : s.api_url
  Object.assign(form.proxy, {
    enabled: s.proxy.enabled,
    type: s.proxy.type,
    host: s.proxy.host ?? '',
    port: s.proxy.port ?? '',
    username: s.proxy.username ?? '',
    password: '',
    clear_password: false,
  })
  snapshot.value = JSON.stringify(form)
}
watch(() => props.settings, fill, { immediate: true })

const dirty = computed(() => JSON.stringify(form) !== snapshot.value)
useUnsavedGuard(dirty)

const typeOptions: { value: ProxyType; label: string }[] = [
  { value: 'http', label: 'HTTP' },
  { value: 'socks5', label: 'SOCKS5' },
]

function payload() {
  const { password, clear_password, port, ...rest } = form.proxy
  return {
    telegram: {
      api_url: form.api_url.trim(),
      proxy: { ...rest, port: port === '' ? null : port, ...(password ? { password } : {}), ...(clear_password && !password ? { clear_password: true } : {}) },
    },
  }
}

async function save(): Promise<boolean> {
  saving.value = true
  errors.value = {}
  formError.value = ''
  savedNotice.value = false
  try {
    const res = await api<{ data: NotificationSettings }>('/settings/notifications', { method: 'PUT', body: payload() })
    emit('saved', res.data)
    fill(res.data.telegram)
    savedNotice.value = true
    return true
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      errors.value = e.errors
      formError.value = unshownErrors(e.errors, ['telegram.api_url', 'telegram.proxy.type', 'telegram.proxy.host', 'telegram.proxy.port', 'telegram.proxy.username', 'telegram.proxy.password'])
    } else formError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
    return false
  } finally {
    saving.value = false
  }
}

// Проверка соединения: несохранённые изменения сначала сохраняются
const testing = ref(false)
const testResult = ref<TestResult | null>(null)

async function check() {
  testResult.value = null
  if (dirty.value && !(await save())) return
  testing.value = true
  try {
    testResult.value = (await api<{ data: TestResult }>('/settings/notifications/test-telegram', { method: 'POST' })).data
  } catch (e) {
    testResult.value = { ok: false, error: e instanceof ApiError ? e.message : 'Не удалось проверить' }
  } finally {
    testing.value = false
  }
}

const err = (k: string) => errors.value[`telegram.${k}`]
const field = 'grid gap-1.5'
</script>

<template>
  <form class="grid gap-4 rounded-xl border bg-card p-6 shadow-xs" @submit.prevent="save">
    <div class="space-y-1">
      <h3 class="flex items-center gap-2 font-semibold"><Icon name="send" class="size-4" />Telegram</h3>
      <p class="text-sm text-muted-foreground">Как SystemKeeper подключается к Telegram Bot API. Токен бота и чат задаются в каждом канале.</p>
    </div>

    <div :class="field">
      <label for="t-url" class="text-sm font-medium">Адрес Bot API</label>
      <input id="t-url" v-model="form.api_url" type="url" :placeholder="DEFAULT_TELEGRAM_API" spellcheck="false" :class="inputClass" class="font-mono" :aria-invalid="!!err('api_url')" />
      <p class="text-xs text-muted-foreground">Пусто — {{ DEFAULT_TELEGRAM_API }}. Можно указать свой прокси-шлюз к Bot API.</p>
      <p v-for="m in err('api_url')" :key="m" class="text-sm text-destructive">{{ m }}</p>
    </div>

    <ToggleSwitch v-model="form.proxy.enabled" label="Ходить через прокси" hint="Если api.telegram.org недоступен с сервера" />

    <template v-if="form.proxy.enabled">
      <div :class="field">
        <span class="text-sm font-medium">Тип прокси</span>
        <SegmentedControl v-model="form.proxy.type" :options="typeOptions" />
        <p v-for="m in err('proxy.type')" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
      <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_8rem]">
        <div :class="field">
          <label for="t-host" class="text-sm font-medium">Хост</label>
          <input id="t-host" v-model="form.proxy.host" placeholder="proxy.example.com" spellcheck="false" autocomplete="off" :class="inputClass" :aria-invalid="!!err('proxy.host')" />
          <p v-for="m in err('proxy.host')" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div :class="field">
          <label for="t-port" class="text-sm font-medium">Порт</label>
          <input id="t-port" v-model.number="form.proxy.port" type="number" min="1" max="65535" :placeholder="form.proxy.type === 'socks5' ? '1080' : '3128'" :class="inputClass" :aria-invalid="!!err('proxy.port')" />
          <p v-for="m in err('proxy.port')" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <div :class="field">
          <label for="t-user" class="text-sm font-medium">Логин</label>
          <input id="t-user" v-model="form.proxy.username" placeholder="необязательно" autocomplete="off" spellcheck="false" :class="inputClass" :aria-invalid="!!err('proxy.username')" />
          <p v-for="m in err('proxy.username')" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div :class="field">
          <label for="t-pass" class="text-sm font-medium">Пароль</label>
          <input id="t-pass" v-model="form.proxy.password" type="password" autocomplete="new-password" :disabled="form.proxy.clear_password" :placeholder="settings.proxy.has_password ? '••• сохранён' : 'необязательно'" :class="inputClass" :aria-invalid="!!err('proxy.password')" />
          <label v-if="settings.proxy.has_password" class="flex items-center gap-2 text-xs text-muted-foreground">
            <input v-model="form.proxy.clear_password" type="checkbox" class="size-4 accent-primary" :disabled="!!form.proxy.password" />Удалить сохранённый пароль
          </label>
          <p v-for="m in err('proxy.password')" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
      </div>
    </template>

    <div class="grid gap-2 border-t pt-4">
      <div class="flex flex-wrap items-center gap-3">
        <button type="button" :class="btnClass.outline" :disabled="testing || saving" @click="check">
          <Icon :name="testing ? 'loader' : 'activity'" :class="{ 'animate-spin': testing }" />Проверить соединение
        </button>
        <p v-if="testResult?.ok" class="flex items-center gap-1.5 text-sm"><Icon name="check" class="size-4 text-primary" />Адрес отвечает</p>
      </div>
      <p v-if="testResult && !testResult.ok" class="text-sm whitespace-pre-wrap text-destructive">{{ testResult.error }}</p>
      <p class="text-xs text-muted-foreground">Проверяется доступность адреса (через прокси, если он включён); токен не нужен. Несохранённые изменения сначала сохраняются.</p>
    </div>

    <SaveBar :dirty="dirty" :saving="saving" :saved="savedNotice" :error="formError" @reset="fill(settings)" />
  </form>
</template>
