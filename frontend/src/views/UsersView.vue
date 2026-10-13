<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { btnClass, formatDate, inputClass } from '@/lib/ui'
import { useAuthStore, type User } from '@/stores/auth'

interface ListedUser extends User {
  created_at: string
}

interface Page {
  data: ListedUser[]
  meta: { current_page: number; last_page: number; total: number }
}

const auth = useAuthStore()

const users = ref<ListedUser[]>([])
const meta = ref<Page['meta'] | null>(null)
const page = ref(1)
const search = ref('')
const loading = ref(false)
const loadError = ref('')

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const qs = new URLSearchParams({ page: String(page.value) })
    if (search.value.trim()) qs.set('search', search.value.trim())
    const res = await api<Page>(`/users?${qs}`)
    users.value = res.data
    meta.value = res.meta
  } catch {
    loadError.value = 'Не удалось загрузить пользователей'
  } finally {
    loading.value = false
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    load()
  }, 300)
})

function goTo(p: number) {
  page.value = p
  load()
}

// Создание и редактирование
const editing = ref<ListedUser | null>(null)
const formOpen = ref(false)
const form = reactive({ name: '', email: '', password: '', is_admin: false })
const formErrors = ref<Record<string, string[]>>({})
const formError = ref('')
const saving = ref(false)

function openForm(user: ListedUser | null) {
  editing.value = user
  Object.assign(form, { name: user?.name ?? '', email: user?.email ?? '', password: '', is_admin: user?.is_admin ?? false })
  formErrors.value = {}
  formError.value = ''
  formOpen.value = true
}

async function save() {
  saving.value = true
  formErrors.value = {}
  formError.value = ''
  const body: Record<string, unknown> = { name: form.name, email: form.email, is_admin: form.is_admin }
  if (form.password || !editing.value) body.password = form.password
  try {
    const res = editing.value
      ? await api<{ data: ListedUser }>(`/users/${editing.value.id}`, { method: 'PATCH', body })
      : await api<{ data: ListedUser }>('/users', { method: 'POST', body })
    if (res.data.id === auth.user?.id) auth.setUser(res.data)
    formOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) formErrors.value = e.errors
    else formError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

// Удаление
const deleting = ref<ListedUser | null>(null)
const deleteError = ref('')

async function confirmDelete() {
  if (!deleting.value) return
  deleteError.value = ''
  try {
    await api(`/users/${deleting.value.id}`, { method: 'DELETE' })
    deleting.value = null
    if (users.value.length === 1 && page.value > 1) page.value--
    await load()
  } catch (e) {
    deleteError.value = e instanceof ApiError ? e.message : 'Не удалось удалить'
  }
}

onMounted(load)

const label = 'text-sm leading-none font-medium'
const iconBtn = 'inline-flex size-8 items-center justify-center rounded-md hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-40'
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div class="space-y-0.5">
        <h2 class="text-xl font-semibold tracking-tight">Пользователи</h2>
        <p class="text-sm text-muted-foreground">Кто может входить в SystemKeeper</p>
      </div>
      <button :class="btnClass.primary" @click="openForm(null)"><Icon name="plus" />Добавить</button>
    </header>

    <input v-model="search" type="search" placeholder="Поиск по имени или почте" :class="inputClass" class="max-w-sm" />

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>

    <div class="overflow-x-auto rounded-xl border bg-card">
      <table class="w-full text-sm">
        <thead class="border-b text-left text-muted-foreground">
          <tr>
            <th class="px-4 py-3 font-medium">Имя</th>
            <th class="px-4 py-3 font-medium">Почта</th>
            <th class="px-4 py-3 font-medium">Роль</th>
            <th class="px-4 py-3 font-medium">Создан</th>
            <th class="w-24 px-4 py-3" />
          </tr>
        </thead>
        <tbody class="divide-y">
          <tr v-for="u in users" :key="u.id">
            <td class="px-4 py-3 font-medium">
              {{ u.name }}
              <span v-if="u.id === auth.user?.id" class="ml-1 text-xs text-muted-foreground">(вы)</span>
            </td>
            <td class="px-4 py-3">{{ u.email }}</td>
            <td class="px-4 py-3">
              <span v-if="u.is_admin" class="rounded-md bg-accent px-1.5 py-0.5 text-xs text-accent-foreground">Администратор</span>
              <span v-else class="text-muted-foreground">Пользователь</span>
            </td>
            <td class="px-4 py-3 whitespace-nowrap text-muted-foreground">{{ formatDate(u.created_at) }}</td>
            <td class="px-4 py-2">
              <div class="flex justify-end gap-1">
                <button :class="iconBtn" title="Изменить" @click="openForm(u)"><Icon name="pencil" /></button>
                <button
                  :class="iconBtn"
                  class="text-destructive"
                  :disabled="u.id === auth.user?.id"
                  :title="u.id === auth.user?.id ? 'Себя удалить нельзя' : 'Удалить'"
                  @click="(deleting = u), (deleteError = '')"
                >
                  <Icon name="trash" />
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="!users.length && !loading">
            <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">{{ search ? 'Никого не нашли' : 'Пользователей нет' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="meta && meta.last_page > 1" class="flex items-center justify-between gap-3 text-sm text-muted-foreground">
      <span>Всего: {{ meta.total }}</span>
      <div class="flex items-center gap-2">
        <button :class="btnClass.outline" :disabled="meta.current_page <= 1" @click="goTo(meta.current_page - 1)">Назад</button>
        <span>{{ meta.current_page }} / {{ meta.last_page }}</span>
        <button :class="btnClass.outline" :disabled="meta.current_page >= meta.last_page" @click="goTo(meta.current_page + 1)">Вперёд</button>
      </div>
    </div>

    <Modal v-if="formOpen" :title="editing ? 'Изменить пользователя' : 'Новый пользователь'" @close="formOpen = false">
      <form class="grid gap-4" @submit.prevent="save">
        <div class="grid gap-2">
          <label for="u-name" :class="label">Имя</label>
          <input id="u-name" v-model="form.name" required :class="inputClass" :aria-invalid="!!formErrors.name" />
          <p v-for="m in formErrors.name" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid gap-2">
          <label for="u-email" :class="label">Почта</label>
          <input id="u-email" v-model="form.email" type="email" required autocomplete="off" :class="inputClass" :aria-invalid="!!formErrors.email" />
          <p v-for="m in formErrors.email" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid gap-2">
          <label for="u-password" :class="label">{{ editing ? 'Новый пароль' : 'Пароль' }}</label>
          <input
            id="u-password"
            v-model="form.password"
            type="password"
            :required="!editing"
            minlength="8"
            autocomplete="new-password"
            :placeholder="editing ? 'Оставьте пустым, чтобы не менять' : 'Минимум 8 символов'"
            :class="inputClass"
            :aria-invalid="!!formErrors.password"
          />
          <p v-if="editing && form.password && editing.id !== auth.user?.id" class="text-sm text-muted-foreground">Все сеансы пользователя будут завершены</p>
          <p v-for="m in formErrors.password" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.is_admin" type="checkbox" class="size-4 accent-primary" :disabled="editing?.id === auth.user?.id" />
          Администратор — может управлять пользователями
        </label>
        <p v-for="m in formErrors.is_admin" :key="m" class="text-sm text-destructive">{{ m }}</p>
        <p v-if="formError" class="text-sm text-destructive">{{ formError }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" :class="btnClass.outline" @click="formOpen = false">Отмена</button>
          <button type="submit" :disabled="saving" :class="btnClass.primary">{{ editing ? 'Сохранить' : 'Создать' }}</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="deleting" title="Удалить пользователя?" @close="deleting = null">
      <p class="text-sm text-muted-foreground">
        {{ deleting.name }} ({{ deleting.email }}) больше не сможет войти, все его сеансы будут завершены.
      </p>
      <p v-if="deleteError" class="mt-3 text-sm text-destructive">{{ deleteError }}</p>
      <div class="mt-6 flex justify-end gap-2">
        <button :class="btnClass.outline" @click="deleting = null">Отмена</button>
        <button :class="btnClass.destructive" @click="confirmDelete">Удалить</button>
      </div>
    </Modal>
  </div>
</template>
