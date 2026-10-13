<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, ApiError } from '@/api/client'
import type { Theme } from '@/lib/theme'
import { btnClass, formatDate, inputClass } from '@/lib/ui'
import { useAuthStore, type User } from '@/stores/auth'

interface Session {
  id: number
  name: string
  current: boolean
  last_used_at: string | null
  created_at: string
}

const auth = useAuthStore()
const router = useRouter()

const themes: { value: Theme; label: string }[] = [
  { value: 'system', label: 'Как в системе' },
  { value: 'light', label: 'Светлая' },
  { value: 'dark', label: 'Тёмная' },
]

/** Состояние формы: ошибки полей, общий текст ошибки/успеха, флаг отправки. */
function formState() {
  return reactive({ busy: false, errors: {} as Record<string, string[]>, error: '', done: '' })
}

async function submit(state: ReturnType<typeof formState>, action: () => Promise<void>, done: string) {
  state.busy = true
  state.errors = {}
  state.error = ''
  state.done = ''
  try {
    await action()
    state.done = done
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) state.errors = e.errors
    else state.error = e instanceof ApiError ? e.message : 'Не удалось сохранить. Проверьте соединение'
  } finally {
    state.busy = false
  }
}

async function saveProfile(body: Record<string, unknown>) {
  auth.setUser((await api<{ data: User }>('/profile', { method: 'PATCH', body })).data)
}

// Основное
const account = reactive({ name: '', email: '', current_password: '' })
const accountState = formState()

function saveAccount() {
  const body: Record<string, unknown> = { name: account.name }
  if (account.email !== auth.user?.email) Object.assign(body, { email: account.email, current_password: account.current_password })
  submit(accountState, async () => {
    await saveProfile(body)
    account.current_password = ''
  }, 'Сохранено')
}

// Настройки
const settings = reactive({ theme: 'system' as Theme })
const settingsState = formState()

function saveSettings() {
  submit(settingsState, () => saveProfile({ settings: { ...settings } }), 'Сохранено')
}

// Пароль
const pwd = reactive({ current_password: '', password: '', password_confirmation: '' })
const pwdState = formState()

function savePassword() {
  submit(pwdState, async () => {
    await api('/profile/password', { method: 'PUT', body: { ...pwd } })
    Object.assign(pwd, { current_password: '', password: '', password_confirmation: '' })
    await loadSessions()
  }, 'Пароль изменён, остальные сеансы завершены')
}

// Сеансы
const sessions = ref<Session[]>([])
const sessionsError = ref('')

async function loadSessions() {
  try {
    sessions.value = (await api<{ data: Session[] }>('/profile/tokens')).data
  } catch {
    sessionsError.value = 'Не удалось загрузить сеансы'
  }
}

async function revoke(s: Session) {
  await api(`/profile/tokens/${s.id}`, { method: 'DELETE' })
  if (s.current) {
    auth.clear()
    router.push({ name: 'login' })
  } else {
    await loadSessions()
  }
}

async function revokeOthers() {
  await api('/profile/tokens', { method: 'DELETE' })
  await loadSessions()
}

onMounted(async () => {
  if (!auth.user) await auth.fetchUser()
  if (auth.user) {
    Object.assign(account, { name: auth.user.name, email: auth.user.email })
    Object.assign(settings, auth.user.settings)
  }
  loadSessions()
})

const card = 'rounded-xl border bg-card p-6 shadow-xs'
const label = 'text-sm leading-none font-medium'
</script>

<template>
  <div class="flex max-w-3xl flex-col gap-6 p-4">
    <header class="space-y-0.5">
      <h2 class="text-xl font-semibold tracking-tight">Профиль</h2>
      <p class="text-sm text-muted-foreground">Данные учётной записи, настройки и сеансы входа</p>
    </header>

    <form :class="card" class="grid gap-4" @submit.prevent="saveAccount">
      <h3 class="font-semibold">Основное</h3>
      <div class="grid gap-2">
        <label for="name" :class="label">Имя</label>
        <input id="name" v-model="account.name" required :class="inputClass" :aria-invalid="!!accountState.errors.name" />
        <p v-for="m in accountState.errors.name" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
      <div class="grid gap-2">
        <label for="email" :class="label">Почта</label>
        <input id="email" v-model="account.email" type="email" required autocomplete="username" :class="inputClass" :aria-invalid="!!accountState.errors.email" />
        <p v-for="m in accountState.errors.email" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
      <div v-if="account.email !== auth.user?.email" class="grid gap-2">
        <label for="email-password" :class="label">Текущий пароль</label>
        <input id="email-password" v-model="account.current_password" type="password" required autocomplete="current-password" :class="inputClass" :aria-invalid="!!accountState.errors.current_password" />
        <p class="text-sm text-muted-foreground">Для смены почты нужно подтвердить паролем</p>
        <p v-for="m in accountState.errors.current_password" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
      <div class="flex items-center gap-3">
        <button type="submit" :disabled="accountState.busy" :class="btnClass.primary">Сохранить</button>
        <span v-if="accountState.done" class="text-sm text-muted-foreground">{{ accountState.done }}</span>
        <span v-if="accountState.error" class="text-sm text-destructive">{{ accountState.error }}</span>
      </div>
    </form>

    <form :class="card" class="grid gap-4" @submit.prevent="saveSettings">
      <h3 class="font-semibold">Настройки</h3>
      <div class="grid gap-2">
        <span :class="label">Тема</span>
        <div class="flex flex-wrap gap-2">
          <label v-for="t in themes" :key="t.value" class="flex h-9 cursor-pointer items-center gap-2 rounded-md border px-3 text-sm has-checked:border-primary has-checked:bg-accent">
            <input v-model="settings.theme" type="radio" name="theme" :value="t.value" class="accent-primary" />{{ t.label }}
          </label>
        </div>
        <p v-for="m in settingsState.errors['settings.theme']" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
      <div class="flex items-center gap-3">
        <button type="submit" :disabled="settingsState.busy" :class="btnClass.primary">Сохранить</button>
        <span v-if="settingsState.done" class="text-sm text-muted-foreground">{{ settingsState.done }}</span>
        <span v-if="settingsState.error" class="text-sm text-destructive">{{ settingsState.error }}</span>
      </div>
    </form>

    <form :class="card" class="grid gap-4" @submit.prevent="savePassword">
      <h3 class="font-semibold">Пароль</h3>
      <div class="grid gap-2">
        <label for="current-password" :class="label">Текущий пароль</label>
        <input id="current-password" v-model="pwd.current_password" type="password" required autocomplete="current-password" :class="inputClass" :aria-invalid="!!pwdState.errors.current_password" />
        <p v-for="m in pwdState.errors.current_password" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="grid gap-2">
          <label for="new-password" :class="label">Новый пароль</label>
          <input id="new-password" v-model="pwd.password" type="password" required minlength="8" autocomplete="new-password" :class="inputClass" :aria-invalid="!!pwdState.errors.password" />
        </div>
        <div class="grid gap-2">
          <label for="new-password-confirm" :class="label">Повторите пароль</label>
          <input id="new-password-confirm" v-model="pwd.password_confirmation" type="password" required autocomplete="new-password" :class="inputClass" />
        </div>
      </div>
      <p v-for="m in pwdState.errors.password" :key="m" class="text-sm text-destructive">{{ m }}</p>
      <div class="flex items-center gap-3">
        <button type="submit" :disabled="pwdState.busy" :class="btnClass.primary">Сменить пароль</button>
        <span v-if="pwdState.done" class="text-sm text-muted-foreground">{{ pwdState.done }}</span>
        <span v-if="pwdState.error" class="text-sm text-destructive">{{ pwdState.error }}</span>
      </div>
    </form>

    <section :class="card" class="grid gap-4">
      <div class="flex items-center justify-between gap-3">
        <h3 class="font-semibold">Сеансы</h3>
        <button v-if="sessions.length > 1" :class="btnClass.outline" @click="revokeOthers">Завершить остальные</button>
      </div>
      <p v-if="sessionsError" class="text-sm text-destructive">{{ sessionsError }}</p>
      <ul class="divide-y">
        <li v-for="s in sessions" :key="s.id" class="flex items-center gap-3 py-3">
          <div class="min-w-0 flex-1">
            <div class="text-sm font-medium">
              {{ s.name }}
              <span v-if="s.current" class="ml-1 rounded-md bg-accent px-1.5 py-0.5 text-xs">этот сеанс</span>
            </div>
            <div class="text-xs text-muted-foreground">Вход {{ formatDate(s.created_at) }} · активность {{ formatDate(s.last_used_at) }}</div>
          </div>
          <button :class="btnClass.destructive" @click="revoke(s)">{{ s.current ? 'Выйти' : 'Завершить' }}</button>
        </li>
      </ul>
    </section>
  </div>
</template>
