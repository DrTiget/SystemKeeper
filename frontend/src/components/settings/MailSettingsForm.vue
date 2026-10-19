<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import type { MailEncryption, NotificationSettings, TestResult } from '@/api/settings'
import Icon from '@/components/Icon.vue'
import SaveBar from '@/components/form/SaveBar.vue'
import SegmentedControl from '@/components/form/SegmentedControl.vue'
import { btnClass, inputClass } from '@/lib/ui'
import { unshownErrors, useUnsavedGuard } from '@/lib/unsaved'
import { useAuthStore } from '@/stores/auth'

/** Карточка «Почта (SMTP)». Пароль не возвращается: пустое поле — оставить прежний. */
const props = defineProps<{ settings: NotificationSettings['mail'] }>()
const emit = defineEmits<{ saved: [settings: NotificationSettings] }>()

const auth = useAuthStore()

const form = reactive({
  host: '',
  port: 587,
  encryption: 'tls' as MailEncryption,
  username: '',
  password: '',
  clear_password: false,
  from_address: '',
  from_name: '',
})
const errors = ref<Record<string, string[]>>({})
const formError = ref('')
const saving = ref(false)
const savedNotice = ref(false)
const snapshot = ref('')

function fill(s: NotificationSettings['mail']) {
  Object.assign(form, {
    host: s.host ?? '',
    port: s.port,
    encryption: s.encryption,
    username: s.username ?? '',
    password: '',
    clear_password: false,
    from_address: s.from_address ?? '',
    from_name: s.from_name ?? '',
  })
  snapshot.value = JSON.stringify(form)
}
watch(() => props.settings, fill, { immediate: true })

const dirty = computed(() => JSON.stringify(form) !== snapshot.value)
useUnsavedGuard(dirty)

const encryptionOptions: { value: MailEncryption; label: string }[] = [
  { value: 'tls', label: 'STARTTLS' },
  { value: 'ssl', label: 'SSL/TLS' },
  { value: 'none', label: 'Без шифрования' },
]
const defaultPorts: Record<MailEncryption, number> = { tls: 587, ssl: 465, none: 25 }

/** Порт меняем вместе с шифрованием, только пока он стандартный — свой порт не трогаем */
watch(() => form.encryption, (_, prev) => {
  if (form.port === defaultPorts[prev]) form.port = defaultPorts[form.encryption]
})

const source = computed(() => {
  if (props.settings.source === 'settings') return { tone: 'text-primary', text: 'Письма уходят через SMTP из этих настроек.' }
  if (props.settings.source === 'env') return { tone: 'text-muted-foreground', text: 'Сейчас письма уходят через почту, заданную на сервере (MAIL_* в .env). Укажите SMTP-сервер ниже, чтобы переопределить её.' }
  return { tone: 'text-amber-600 dark:text-amber-500', text: 'Почта не настроена — письма никуда не уходят. Укажите SMTP-сервер, чтобы создавать каналы «Почта».' }
})

function payload() {
  const { password, clear_password, ...rest } = form
  return { mail: { ...rest, ...(password ? { password } : {}), ...(clear_password && !password ? { clear_password: true } : {}) } }
}

async function save(): Promise<boolean> {
  saving.value = true
  errors.value = {}
  formError.value = ''
  savedNotice.value = false
  try {
    const res = await api<{ data: NotificationSettings }>('/settings/notifications', { method: 'PUT', body: payload() })
    emit('saved', res.data)
    fill(res.data.mail)
    savedNotice.value = true
    return true
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      errors.value = e.errors
      formError.value = unshownErrors(e.errors, ['mail.host', 'mail.port', 'mail.encryption', 'mail.username', 'mail.password', 'mail.from_address', 'mail.from_name'])
    } else formError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
    return false
  } finally {
    saving.value = false
  }
}

// Тестовое письмо: на адрес текущего пользователя; несохранённые изменения сначала сохраняются
const testTo = ref(auth.user?.email ?? '')
const testing = ref(false)
const testResult = ref<TestResult | null>(null)

async function sendTest() {
  testResult.value = null
  if (dirty.value && !(await save())) return
  testing.value = true
  try {
    testResult.value = (await api<{ data: TestResult }>('/settings/notifications/test-mail', { method: 'POST', body: { to: testTo.value.trim() } })).data
  } catch (e) {
    testResult.value = { ok: false, error: e instanceof ApiError ? Object.values(e.errors).flat()[0] ?? e.message : 'Не удалось отправить' }
  } finally {
    testing.value = false
  }
}

const err = (k: string) => errors.value[`mail.${k}`]
const field = 'grid gap-1.5'
</script>

<template>
  <form class="grid gap-4 rounded-xl border bg-card p-6 shadow-xs" @submit.prevent="save">
    <div class="space-y-1">
      <h3 class="flex items-center gap-2 font-semibold"><Icon name="mail" class="size-4" />Почта (SMTP)</h3>
      <p class="text-sm" :class="source.tone">{{ source.text }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_8rem]">
      <div :class="field">
        <label for="m-host" class="text-sm font-medium">SMTP-сервер</label>
        <input id="m-host" v-model="form.host" placeholder="smtp.example.com" spellcheck="false" autocomplete="off" :class="inputClass" :aria-invalid="!!err('host')" />
        <p v-for="m in err('host')" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
      <div :class="field">
        <label for="m-port" class="text-sm font-medium">Порт</label>
        <input id="m-port" v-model.number="form.port" type="number" min="1" max="65535" :class="inputClass" :aria-invalid="!!err('port')" />
        <p v-for="m in err('port')" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
    </div>

    <div :class="field">
      <span class="text-sm font-medium">Шифрование</span>
      <SegmentedControl v-model="form.encryption" :options="encryptionOptions" />
      <p v-for="m in err('encryption')" :key="m" class="text-sm text-destructive">{{ m }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
      <div :class="field">
        <label for="m-user" class="text-sm font-medium">Логин</label>
        <input id="m-user" v-model="form.username" autocomplete="off" spellcheck="false" :class="inputClass" :aria-invalid="!!err('username')" />
        <p v-for="m in err('username')" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
      <div :class="field">
        <label for="m-pass" class="text-sm font-medium">Пароль</label>
        <input id="m-pass" v-model="form.password" type="password" autocomplete="new-password" :disabled="form.clear_password" :placeholder="settings.has_password ? '••• сохранён' : ''" :class="inputClass" :aria-invalid="!!err('password')" />
        <label v-if="settings.has_password" class="flex items-center gap-2 text-xs text-muted-foreground">
          <input v-model="form.clear_password" type="checkbox" class="size-4 accent-primary" :disabled="!!form.password" />Удалить сохранённый пароль
        </label>
        <p v-for="m in err('password')" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
      <div :class="field">
        <label for="m-from" class="text-sm font-medium">Адрес отправителя</label>
        <input id="m-from" v-model="form.from_address" type="email" placeholder="alerts@example.com" spellcheck="false" :class="inputClass" :aria-invalid="!!err('from_address')" />
        <p v-for="m in err('from_address')" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
      <div :class="field">
        <label for="m-name" class="text-sm font-medium">Имя отправителя</label>
        <input id="m-name" v-model="form.from_name" placeholder="SystemKeeper" :class="inputClass" :aria-invalid="!!err('from_name')" />
        <p v-for="m in err('from_name')" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
    </div>

    <div class="grid gap-2 border-t pt-4">
      <label for="m-test" class="text-sm font-medium">Проверка</label>
      <div class="flex flex-wrap gap-2">
        <input id="m-test" v-model="testTo" type="email" placeholder="адрес получателя" spellcheck="false" :class="inputClass" class="max-w-xs min-w-52 flex-1" />
        <button type="button" :class="btnClass.outline" :disabled="testing || saving || !testTo.trim()" @click="sendTest">
          <Icon :name="testing ? 'loader' : 'send'" :class="{ 'animate-spin': testing }" />Отправить тестовое письмо
        </button>
      </div>
      <p class="text-xs text-muted-foreground">Если в форме есть несохранённые изменения, они сохранятся перед отправкой.</p>
      <p v-if="testResult?.ok" class="flex items-center gap-1.5 text-sm"><Icon name="check" class="size-4 text-primary" />Письмо отправлено — проверьте ящик.</p>
      <p v-else-if="testResult" class="text-sm whitespace-pre-wrap text-destructive">{{ testResult.error }}</p>
    </div>

    <SaveBar :dirty="dirty" :saving="saving" :saved="savedNotice" :error="formError" @reset="fill(settings)" />
  </form>
</template>
