<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { api, ApiError } from '@/api/client'
import type { AuthType, Server } from '@/api/servers'
import Modal from '@/components/Modal.vue'
import ProjectSelect from '@/components/ProjectSelect.vue'
import { btnClass, inputClass } from '@/lib/ui'

/** keep — оставить сохранённый ключ, generate — сгенерировать пару в сервисе, paste — вставить свой. */
type KeySource = 'keep' | 'generate' | 'paste'

const props = defineProps<{ server: Server | null; projectId?: number }>()
const emit = defineEmits<{ close: []; saved: [server: Server, generatedKey: boolean]; showKey: [server: Server] }>()

const editing = props.server
const form = reactive({
  name: editing?.name ?? '',
  description: editing?.description ?? '',
  project_id: editing ? (editing.project?.id ?? null) : (props.projectId ?? null),
  host: editing?.host ?? '',
  port: editing?.port ?? 22,
  username: editing?.username ?? 'root',
  auth_type: (editing?.auth_type ?? 'key') as AuthType,
  key_source: (editing?.auth_type === 'key' ? 'keep' : 'generate') as KeySource,
  key_type: 'ed25519' as 'ed25519' | 'rsa',
  private_key: '',
  passphrase: '',
  password: '',
})
const errors = ref<Record<string, string[]>>({})
const formError = ref('')
const saving = ref(false)

/** При редактировании без смены способа авторизации учётные данные можно не вводить — останутся прежние. */
const keepsCredential = computed(() => !!editing && editing.auth_type === form.auth_type)

watch(keepsCredential, (keeps) => {
  if (!keeps && form.key_source === 'keep') form.key_source = 'generate'
})

async function save() {
  saving.value = true
  errors.value = {}
  formError.value = ''
  const body: Record<string, unknown> = {
    name: form.name,
    description: form.description || null,
    project_id: form.project_id,
    host: form.host.trim(),
    port: form.port,
    username: form.username,
    auth_type: form.auth_type,
  }
  if (form.auth_type === 'key' && form.key_source === 'generate') {
    body.generate_key = true
    body.key_type = form.key_type
  }
  if (form.auth_type === 'key' && form.key_source === 'paste') {
    body.private_key = form.private_key.trim() + '\n'
    body.passphrase = form.passphrase || null
  }
  if (form.auth_type === 'password' && form.password) body.password = form.password
  try {
    const res = editing
      ? await api<{ data: Server }>(`/servers/${editing.id}`, { method: 'PATCH', body })
      : await api<{ data: Server }>('/servers', { method: 'POST', body })
    emit('saved', res.data, !!body.generate_key)
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) errors.value = e.errors
    else formError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

const label = 'text-sm leading-none font-medium'
const segment = 'flex h-9 flex-1 cursor-pointer items-center justify-center rounded-md border text-sm has-checked:border-primary has-checked:bg-accent has-checked:text-accent-foreground'
</script>

<template>
  <Modal wide :title="editing ? 'Изменить подключение' : 'Новый сервер'" @close="emit('close')">
    <form class="grid gap-4" @submit.prevent="save">
      <div class="grid gap-2">
        <label for="s-name" :class="label">Название</label>
        <input id="s-name" v-model="form.name" required placeholder="web-1" :class="inputClass" :aria-invalid="!!errors.name" />
        <p v-for="m in errors.name" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
      <div class="grid gap-2">
        <label for="s-description" :class="label">Описание</label>
        <input id="s-description" v-model="form.description" placeholder="Необязательно" :class="inputClass" :aria-invalid="!!errors.description" />
        <p v-for="m in errors.description" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
      <div class="grid gap-2">
        <label for="s-project" :class="label">Проект</label>
        <ProjectSelect id="s-project" v-model="form.project_id" :invalid="!!errors.project_id" />
        <p v-for="m in errors.project_id" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
      <div class="grid grid-cols-[1fr_6rem] gap-4">
        <div class="grid gap-2">
          <label for="s-host" :class="label">Хост</label>
          <input id="s-host" v-model="form.host" required placeholder="192.168.1.10 или example.com" :class="inputClass" :aria-invalid="!!errors.host" />
        </div>
        <div class="grid gap-2">
          <label for="s-port" :class="label">Порт</label>
          <input id="s-port" v-model.number="form.port" type="number" min="1" max="65535" required :class="inputClass" :aria-invalid="!!errors.port" />
        </div>
      </div>
      <p v-for="m in [...(errors.host ?? []), ...(errors.port ?? [])]" :key="m" class="-mt-2 text-sm text-destructive">{{ m }}</p>
      <div class="grid gap-2">
        <label for="s-username" :class="label">Пользователь</label>
        <input id="s-username" v-model="form.username" required autocomplete="off" :class="inputClass" :aria-invalid="!!errors.username" />
        <p v-for="m in errors.username" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>

      <div class="grid gap-2">
        <span :class="label">Авторизация</span>
        <div class="flex gap-2">
          <label :class="segment"><input v-model="form.auth_type" type="radio" value="key" class="sr-only" />По ключу</label>
          <label :class="segment"><input v-model="form.auth_type" type="radio" value="password" class="sr-only" />По паролю</label>
        </div>
      </div>

      <template v-if="form.auth_type === 'key'">
        <div class="grid gap-2">
          <span :class="label">Ключ</span>
          <div class="flex gap-2">
            <label v-if="keepsCredential" :class="segment"><input v-model="form.key_source" type="radio" value="keep" class="sr-only" />Текущий</label>
            <label :class="segment"><input v-model="form.key_source" type="radio" value="generate" class="sr-only" />{{ keepsCredential ? 'Новый' : 'Сгенерировать' }}</label>
            <label :class="segment"><input v-model="form.key_source" type="radio" value="paste" class="sr-only" />Вставить свой</label>
          </div>
        </div>
        <div v-if="form.key_source === 'generate'" class="grid gap-2">
          <span :class="label">Тип ключа</span>
          <div class="flex gap-2">
            <label :class="segment"><input v-model="form.key_type" type="radio" value="ed25519" class="sr-only" />Ed25519</label>
            <label :class="segment"><input v-model="form.key_type" type="radio" value="rsa" class="sr-only" />RSA 4096</label>
          </div>
          <p class="text-sm text-muted-foreground">
            Пара ключей создаётся в SystemKeeper, закрытый ключ хранится зашифрованным и никуда не выдаётся. После сохранения покажем открытый ключ — его нужно добавить в <code class="font-mono text-xs">~/.ssh/authorized_keys</code> на сервере.
            <template v-if="keepsCredential"> Текущий ключ будет заменён.</template>
          </p>
        </div>
        <div v-if="form.key_source === 'keep' && editing?.public_key">
          <button type="button" :class="btnClass.outline" @click="emit('showKey', editing)">Показать открытый ключ</button>
        </div>
        <template v-if="form.key_source === 'paste'">
          <div class="grid gap-2">
            <label for="s-key" :class="label">Закрытый ключ</label>
            <textarea
              id="s-key"
              v-model="form.private_key"
              required
              rows="6"
              spellcheck="false"
              placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
              :class="inputClass"
              class="h-auto py-2 font-mono text-xs"
              :aria-invalid="!!errors.private_key"
            />
            <p v-for="m in errors.private_key" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
          <div class="grid gap-2">
            <label for="s-passphrase" :class="label">Парольная фраза ключа</label>
            <input id="s-passphrase" v-model="form.passphrase" type="password" autocomplete="new-password" placeholder="Если ключ зашифрован" :class="inputClass" :aria-invalid="!!errors.passphrase" />
            <p v-for="m in errors.passphrase" :key="m" class="text-sm text-destructive">{{ m }}</p>
          </div>
        </template>
      </template>
      <div v-else class="grid gap-2">
        <label for="s-password" :class="label">Пароль</label>
        <input
          id="s-password"
          v-model="form.password"
          type="password"
          :required="!keepsCredential"
          autocomplete="new-password"
          :placeholder="keepsCredential ? 'Пароль сохранён. Введите новый, чтобы заменить' : ''"
          :class="inputClass"
          :aria-invalid="!!errors.password"
        />
        <p v-for="m in errors.password" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>

      <p v-if="formError" class="text-sm text-destructive">{{ formError }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" :class="btnClass.outline" @click="emit('close')">Отмена</button>
        <button type="submit" :disabled="saving" :class="btnClass.primary">{{ editing ? 'Сохранить' : 'Добавить' }}</button>
      </div>
    </form>
  </Modal>
</template>
