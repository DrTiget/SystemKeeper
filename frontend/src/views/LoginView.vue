<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '@/components/Icon.vue'
import { ApiError } from '@/api/client'
import { inputClass } from '@/lib/ui'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
const loading = ref(false)
const error = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

async function submit() {
  loading.value = true
  error.value = ''
  fieldErrors.value = {}
  try {
    await auth.login(email.value, password.value)
    await router.push({ name: 'dashboard' })
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      fieldErrors.value = e.errors
      error.value = e.errors.email?.length ? '' : 'Проверьте введённые данные'
      if (!Object.keys(e.errors).length) error.value = 'Неверная почта или пароль'
    } else if (e instanceof ApiError && e.status === 429) {
      error.value = 'Слишком много попыток входа. Подождите минуту и повторите'
    } else {
      error.value = 'Не удалось выполнить вход. Проверьте соединение и повторите'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
    <div class="w-full max-w-sm">
      <div class="flex flex-col gap-8">
        <div class="flex flex-col items-center gap-4">
          <div class="flex flex-col items-center gap-2">
            <div class="mb-1 flex size-14 items-center justify-center rounded-2xl bg-ink-600 text-white shadow-sm">
              <Icon name="logo" class="size-8" />
            </div>
            <span class="text-2xl font-extrabold tracking-tight">System<span class="text-link">Keeper</span></span>
          </div>
          <div class="space-y-2 text-center">
            <h1 class="text-xl font-bold tracking-tight">Вход</h1>
            <p class="text-sm text-muted-foreground">Введите почту и пароль администратора</p>
          </div>
        </div>

        <form class="flex flex-col gap-6" @submit.prevent="submit">
          <div v-if="error" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ error }}</div>
          <div class="grid gap-2">
            <label for="email" class="text-sm leading-none font-medium">Почта</label>
            <input id="email" v-model="email" type="email" required autofocus autocomplete="username" placeholder="admin@example.com" :class="inputClass" :aria-invalid="!!fieldErrors.email" />
            <p v-for="m in fieldErrors.email" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div class="grid gap-2">
            <label for="password" class="text-sm leading-none font-medium">Пароль</label>
            <input id="password" v-model="password" type="password" required autocomplete="current-password" :class="inputClass" :aria-invalid="!!fieldErrors.password" />
            <p v-for="m in fieldErrors.password" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <button type="submit" :disabled="loading" class="inline-flex h-9 w-full items-center justify-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground transition-all hover:bg-primary/90 focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50">
            <Icon v-if="loading" name="loader" class="animate-spin" />
            Войти
          </button>
        </form>
      </div>
    </div>
  </div>
</template>
