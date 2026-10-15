<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import type { DatabaseConnection, Engine, Via } from '@/api/databases'
import type { Server } from '@/api/servers'
import Modal from '@/components/Modal.vue'
import ProjectSelect from '@/components/ProjectSelect.vue'
import SelectMenu from '@/components/SelectMenu.vue'
import { btnClass, inputClass } from '@/lib/ui'

const props = defineProps<{ connection: DatabaseConnection | null; serverId?: number; projectId?: number }>()
const emit = defineEmits<{ close: []; saved: [connection: DatabaseConnection, provision: boolean] }>()

const editing = props.connection
const DEFAULT_PORTS: Record<Engine, number> = { mysql: 3306, postgres: 5432, clickhouse: 9000 }
const DEFAULT_USERS: Record<Engine, string> = { mysql: 'root', postgres: 'postgres', clickhouse: 'default' }
const CLIENTS: Record<Engine, string> = { mysql: 'mysql', postgres: 'psql', clickhouse: 'clickhouse-client' }

const form = reactive({
  name: editing?.name ?? '',
  description: editing?.description ?? '',
  project_id: editing ? (editing.project?.id ?? null) : (props.projectId ?? null),
  engine: (editing?.engine ?? 'mysql') as Engine,
  via: (editing?.via ?? (props.serverId ? 'ssh' : 'ssh')) as Via,
  server_id: editing?.server?.id ?? props.serverId ?? (null as number | null),
  use_docker: !!editing?.docker_container,
  docker_container: editing?.docker_container ?? '',
  host: editing?.host ?? '127.0.0.1',
  port: editing?.port ?? 3306,
  username: editing?.username ?? 'root',
  password: '',
  maintenance_database: editing?.maintenance_database ?? '',
  // Новое подключение через сервер: пользователя для бекапов SystemKeeper может создать сам
  auto_user: !editing,
})
const autoUser = computed(() => form.auto_user && form.via === 'ssh' && !editing && form.engine !== 'clickhouse')
const errors = ref<Record<string, string[]>>({})
const formError = ref('')
const saving = ref(false)

// Порт подставляется по СУБД, пока пользователь его не менял
watch(
  () => form.engine,
  (engine, prev) => {
    if (form.port === DEFAULT_PORTS[prev]) form.port = DEFAULT_PORTS[engine]
    if (form.username === DEFAULT_USERS[prev]) form.username = DEFAULT_USERS[engine]
    // автонастройки пользователя для ClickHouse нет — логин и пароль вводятся вручную
    if (engine === 'clickhouse') form.auto_user = false
  },
)

const servers = ref<Server[]>([])
const serverOptions = computed(() => servers.value.map((s) => ({ value: s.id, label: s.name, hint: s.host })))

onMounted(async () => {
  try {
    servers.value = (await api<{ data: Server[] }>('/servers?per_page=100')).data
  } catch {
    // список серверов не загрузился — поле покажет пустой выбор
  }
})

async function save() {
  saving.value = true
  errors.value = {}
  formError.value = ''
  const body: Record<string, unknown> = {
    name: form.name,
    description: form.description || null,
    project_id: form.project_id,
    engine: form.engine,
    via: form.via,
    host: form.host.trim(),
    port: form.port,
    username: form.username,
    maintenance_database: form.engine === 'postgres' ? form.maintenance_database || null : null,
  }
  if (form.via === 'ssh') {
    body.server_id = form.server_id
    body.docker_container = form.use_docker ? form.docker_container.trim() || null : null
  }
  if (autoUser.value) {
    // временно — после сохранения откроется автонастройка, она заменит логин и пароль
    body.username = 'systemkeeper_backup'
    body.password = null
  } else if (form.password || !editing) body.password = form.password || null
  try {
    const res = editing
      ? await api<{ data: DatabaseConnection }>(`/databases/${editing.id}`, { method: 'PATCH', body })
      : await api<{ data: DatabaseConnection }>('/databases', { method: 'POST', body })
    emit('saved', res.data, autoUser.value)
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) errors.value = e.errors
    else formError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

const label = 'text-sm leading-none font-medium'
const hint = 'text-sm text-muted-foreground'
const segment = 'flex h-9 flex-1 cursor-pointer items-center justify-center rounded-md border px-2 text-sm has-checked:border-primary has-checked:bg-accent has-checked:text-accent-foreground'
</script>

<template>
  <Modal wide :title="editing ? 'Изменить подключение' : 'Новая база данных'" @close="emit('close')">
    <form class="grid gap-4" @submit.prevent="save">
      <div class="grid gap-2">
        <label for="d-name" :class="label">Название</label>
        <input id="d-name" v-model="form.name" required placeholder="Магазин — MySQL" :class="inputClass" :aria-invalid="!!errors.name" />
        <p v-for="m in errors.name" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
      <div class="grid gap-2">
        <label for="d-description" :class="label">Описание</label>
        <input id="d-description" v-model="form.description" placeholder="Необязательно" :class="inputClass" />
      </div>
      <div class="grid gap-2">
        <label for="d-project" :class="label">Проект</label>
        <ProjectSelect id="d-project" v-model="form.project_id" :invalid="!!errors.project_id" />
        <p v-for="m in errors.project_id" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>

      <div class="grid gap-2">
        <span :class="label">СУБД</span>
        <div class="flex gap-2">
          <label :class="segment"><input v-model="form.engine" type="radio" value="mysql" class="sr-only" />MySQL / MariaDB</label>
          <label :class="segment"><input v-model="form.engine" type="radio" value="postgres" class="sr-only" />PostgreSQL</label>
          <label :class="segment"><input v-model="form.engine" type="radio" value="clickhouse" class="sr-only" />ClickHouse</label>
        </div>
      </div>

      <div class="grid gap-2">
        <span :class="label">Как подключаться</span>
        <div class="flex gap-2">
          <label :class="segment"><input v-model="form.via" type="radio" value="ssh" class="sr-only" />Через сервер (SSH)</label>
          <label :class="segment"><input v-model="form.via" type="radio" value="direct" class="sr-only" />Напрямую</label>
        </div>
        <p :class="hint">
          <template v-if="form.via === 'ssh'">Клиент {{ CLIENTS[form.engine] }} запускается на выбранном сервере — база может быть закрыта снаружи. Обычно хост — 127.0.0.1.</template>
          <template v-else>SystemKeeper подключается к СУБД сам — порт должен быть доступен из сети.</template>
        </p>
      </div>

      <template v-if="form.via === 'ssh'">
        <div class="grid gap-2">
          <label for="d-server" :class="label">Сервер</label>
          <SelectMenu id="d-server" v-model="form.server_id as number" :options="serverOptions" placeholder="Выберите сервер" :invalid="!!errors.server_id" />
          <p v-for="m in errors.server_id" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <label class="flex items-center gap-2 text-sm">
          <input v-model="form.use_docker" type="checkbox" class="size-4 accent-primary" />
          СУБД работает в Docker-контейнере
        </label>
        <div v-if="form.use_docker" class="grid gap-2">
          <label for="d-container" :class="label">Имя контейнера</label>
          <input id="d-container" v-model="form.docker_container" required placeholder="mysql" spellcheck="false" :class="inputClass" class="font-mono" :aria-invalid="!!errors.docker_container" />
          <p :class="hint">Команды выполняются через <code class="font-mono text-xs">docker exec</code>; хост — адрес СУБД внутри контейнера, обычно 127.0.0.1.</p>
          <p v-for="m in errors.docker_container" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
      </template>

      <div class="grid grid-cols-[1fr_6rem] gap-4">
        <div class="grid gap-2">
          <label for="d-host" :class="label">Хост СУБД</label>
          <input id="d-host" v-model="form.host" required :class="inputClass" :aria-invalid="!!errors.host" />
        </div>
        <div class="grid gap-2">
          <label for="d-port" :class="label">Порт</label>
          <input id="d-port" v-model.number="form.port" type="number" min="1" max="65535" required :class="inputClass" :aria-invalid="!!errors.port" />
        </div>
      </div>
      <p v-for="m in [...(errors.host ?? []), ...(errors.port ?? [])]" :key="m" class="-mt-2 text-sm text-destructive">{{ m }}</p>

      <div v-if="form.via === 'ssh' && !editing && form.engine !== 'clickhouse'" class="grid gap-2">
        <span :class="label">Пользователь БД</span>
        <div class="flex gap-2">
          <label :class="segment"><input v-model="form.auto_user" type="radio" :value="true" class="sr-only" />Создать автоматически</label>
          <label :class="segment"><input v-model="form.auto_user" type="radio" :value="false" class="sr-only" />Указать свой</label>
        </div>
        <p v-if="autoUser" :class="hint">После сохранения SystemKeeper создаст в СУБД пользователя только для чтения со случайным паролем — вводить логин и пароль не нужно.</p>
      </div>

      <div v-if="!autoUser" class="grid gap-4 sm:grid-cols-2">
        <div class="grid gap-2">
          <label for="d-user" :class="label">Пользователь</label>
          <input id="d-user" v-model="form.username" required autocomplete="off" :class="inputClass" :aria-invalid="!!errors.username" />
          <p v-for="m in errors.username" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid gap-2">
          <label for="d-password" :class="label">Пароль</label>
          <input
            id="d-password"
            v-model="form.password"
            type="password"
            autocomplete="new-password"
            :placeholder="editing?.has_password ? 'Сохранён. Введите новый, чтобы заменить' : 'Если нужен'"
            :class="inputClass"
            :aria-invalid="!!errors.password"
          />
          <p v-for="m in errors.password" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
      </div>
      <p v-if="!autoUser" :class="hint" class="-mt-2">Достаточно пользователя с правами на чтение: {{ form.engine === 'mysql' ? 'SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT' : form.engine === 'clickhouse' ? 'SELECT и SHOW TABLES, SHOW DATABASES на нужные базы; порт — нативный (9000), не HTTP' : 'CONNECT и SELECT на нужные базы' }}.</p>

      <div v-if="form.engine === 'postgres'" class="grid gap-2">
        <label for="d-maint" :class="label">База для подключения</label>
        <input id="d-maint" v-model="form.maintenance_database" placeholder="postgres" :class="inputClass" />
        <p :class="hint">Через неё получаем список баз. Обычно postgres.</p>
      </div>

      <p v-if="formError" class="text-sm text-destructive">{{ formError }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" :class="btnClass.outline" @click="emit('close')">Отмена</button>
        <button type="submit" :disabled="saving" :class="btnClass.primary">{{ editing ? 'Сохранить' : 'Добавить' }}</button>
      </div>
    </form>
  </Modal>
</template>
