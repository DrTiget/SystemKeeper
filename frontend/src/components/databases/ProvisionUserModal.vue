<script setup lang="ts">
import { reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { DatabaseConnection } from '@/api/databases'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { btnClass, inputClass } from '@/lib/ui'

const props = defineProps<{ connection: DatabaseConnection }>()
const emit = defineEmits<{ close: []; done: [connection: DatabaseConnection] }>()

const c = props.connection
const isMysql = c.engine === 'mysql'

const form = reactive({
  username: 'systemkeeper_backup',
  mode: 'local' as 'local' | 'credentials',
  admin_username: isMysql ? 'root' : 'postgres',
  admin_password: '',
})
const running = ref(false)
const error = ref('')
const errors = ref<Record<string, string[]>>({})
const log = ref<string[] | null>(null)

async function run() {
  running.value = true
  error.value = ''
  errors.value = {}
  try {
    const res = await api<{ data: { log: string[]; connection: DatabaseConnection } }>(`/databases/${c.id}/provision-user`, {
      method: 'POST',
      body: {
        username: form.username,
        admin: form.mode === 'local' ? { mode: 'local' } : { mode: 'credentials', username: form.admin_username, password: form.admin_password },
      },
    })
    log.value = res.data.log
    emit('done', res.data.connection)
  } catch (e) {
    if (e instanceof ApiError && e.status === 422 && Object.keys(e.errors).length) errors.value = e.errors
    else error.value = e instanceof ApiError ? e.message : 'Не удалось создать пользователя'
  } finally {
    running.value = false
  }
}

const label = 'text-sm leading-none font-medium'
const hint = 'text-sm text-muted-foreground'
const optionCard = 'flex cursor-pointer gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-accent/50'
</script>

<template>
  <Modal wide title="Пользователь для бекапов" @close="emit('close')">
    <div v-if="log" class="grid gap-4 text-sm">
      <p class="flex items-center gap-2 font-medium"><span class="size-2 rounded-full bg-emerald-500" />Готово — подключение использует нового пользователя</p>
      <ul class="grid gap-1 text-muted-foreground">
        <li v-for="l in log" :key="l" class="flex gap-2"><Icon name="check" class="mt-0.5 size-4 shrink-0 text-primary" />{{ l }}</li>
      </ul>
      <p :class="hint">Пароль сгенерирован и сохранён в SystemKeeper в зашифрованном виде. Учётные данные администратора не сохранялись.</p>
      <div class="flex justify-end"><button :class="btnClass.primary" @click="emit('close')">Закрыть</button></div>
    </div>

    <form v-else class="grid gap-4" @submit.prevent="run">
      <p :class="hint">
        SystemKeeper подключится к серверу <b class="text-foreground">{{ c.server?.name }}</b>{{ c.docker_container ? `, контейнер ${c.docker_container},` : '' }} и создаст в
        {{ isMysql ? 'MySQL' : 'PostgreSQL' }} отдельного пользователя с правами только на чтение
        ({{ isMysql ? 'SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT, PROCESS' : 'роль pg_read_all_data' }}) и случайным паролем. Если пользователь уже есть, ему будет задан новый пароль.
      </p>

      <div class="grid gap-2">
        <label for="p-user" :class="label">Имя пользователя</label>
        <input id="p-user" v-model="form.username" required spellcheck="false" :class="inputClass" class="font-mono" :aria-invalid="!!errors.username" />
        <p v-for="m in errors.username" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>

      <div class="grid gap-2">
        <span :class="label">Как получить права администратора БД</span>
        <label :class="optionCard">
          <input v-model="form.mode" type="radio" value="local" class="mt-0.5 size-4 accent-primary" />
          <span class="grid gap-0.5">
            <span class="text-sm font-medium">Автоматически, через доступ к серверу</span>
            <span class="text-xs text-muted-foreground">
              <template v-if="c.docker_container">Root-пароль берётся из переменных контейнера ({{ isMysql ? 'MYSQL_ROOT_PASSWORD / MARIADB_ROOT_PASSWORD' : 'POSTGRES_USER' }}) — так работают официальные образы.</template>
              <template v-else-if="isMysql">Вход как root MySQL по сокету — от root или через sudo без пароля (стандартная установка в Debian/Ubuntu).</template>
              <template v-else>Вход как пользователь postgres — от root или через sudo без пароля.</template>
            </span>
          </span>
        </label>
        <label :class="optionCard">
          <input v-model="form.mode" type="radio" value="credentials" class="mt-0.5 size-4 accent-primary" />
          <span class="grid gap-0.5">
            <span class="text-sm font-medium">Ввести логин администратора БД</span>
            <span class="text-xs text-muted-foreground">Используется один раз для создания пользователя и нигде не сохраняется.</span>
          </span>
        </label>
      </div>

      <div v-if="form.mode === 'credentials'" class="grid gap-4 sm:grid-cols-2">
        <div class="grid gap-2">
          <label for="p-admin" :class="label">Администратор</label>
          <input id="p-admin" v-model="form.admin_username" required autocomplete="off" :class="inputClass" :aria-invalid="!!errors['admin.username']" />
          <p v-for="m in errors['admin.username']" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid gap-2">
          <label for="p-admin-pw" :class="label">Пароль администратора</label>
          <input id="p-admin-pw" v-model="form.admin_password" type="password" autocomplete="new-password" :class="inputClass" />
        </div>
      </div>

      <p v-if="error" class="text-sm whitespace-pre-wrap text-destructive">{{ error }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" :class="btnClass.outline" @click="emit('close')">{{ error ? 'Закрыть' : 'Отмена' }}</button>
        <button type="submit" :disabled="running" :class="btnClass.primary">
          <Icon v-if="running" name="loader" class="animate-spin" />Создать пользователя
        </button>
      </div>
    </form>
  </Modal>
</template>
