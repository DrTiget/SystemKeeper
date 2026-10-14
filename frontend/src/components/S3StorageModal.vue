<script setup lang="ts">
import { reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { BackupStorage } from '@/api/storages'
import Modal from '@/components/Modal.vue'
import { btnClass, inputClass } from '@/lib/ui'

const props = defineProps<{ storage: BackupStorage | null }>()
const emit = defineEmits<{ close: []; saved: [storage: BackupStorage] }>()

const s3 = props.storage?.s3
const form = reactive({
  name: props.storage?.name ?? '',
  endpoint: s3?.endpoint ?? '',
  region: s3?.region ?? '',
  bucket: s3?.bucket ?? '',
  prefix: s3?.prefix ?? '',
  path_style: s3?.path_style ?? false,
  key: s3?.key ?? '',
  secret: '',
})
const errors = ref<Record<string, string[]>>({})
const formError = ref('')
const saving = ref(false)

// Частые S3-совместимые сервисы — подставляют адрес и регион
const PRESETS = [
  { label: 'Yandex Cloud', endpoint: 'https://storage.yandexcloud.net', region: 'ru-central1', path_style: false },
  { label: 'Selectel', endpoint: 'https://s3.ru-1.storage.selcloud.ru', region: 'ru-1', path_style: true },
  { label: 'VK Cloud', endpoint: 'https://hb.ru-msk.vkcloud-storage.ru', region: 'ru-msk', path_style: false },
  { label: 'Amazon S3', endpoint: '', region: 'eu-central-1', path_style: false },
  { label: 'MinIO', endpoint: 'http://minio:9000', region: 'us-east-1', path_style: true },
]
function preset(p: (typeof PRESETS)[number]) {
  form.endpoint = p.endpoint
  form.region = p.region
  form.path_style = p.path_style
  if (!form.name) form.name = p.label
}

async function save() {
  saving.value = true
  errors.value = {}
  formError.value = ''
  const body: Record<string, unknown> = {
    name: form.name,
    endpoint: form.endpoint.trim() || null,
    region: form.region.trim() || null,
    bucket: form.bucket.trim(),
    prefix: form.prefix.trim() || null,
    path_style: form.path_style,
    key: form.key.trim(),
  }
  if (form.secret || !props.storage) body.secret = form.secret
  try {
    const res = props.storage
      ? await api<{ data: BackupStorage }>(`/storages/${props.storage.id}`, { method: 'PATCH', body })
      : await api<{ data: BackupStorage }>('/storages', { method: 'POST', body: { ...body, type: 's3' } })
    emit('saved', res.data)
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) errors.value = e.errors
    else formError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

const label = 'text-sm leading-none font-medium'
const hint = 'text-sm text-muted-foreground'
</script>

<template>
  <Modal wide :title="storage ? 'Изменить S3' : 'Подключить S3'" @close="emit('close')">
    <form class="grid gap-4" @submit.prevent="save">
      <div v-if="!storage" class="flex flex-wrap items-center gap-2 text-sm">
        <span class="text-muted-foreground">Заполнить для:</span>
        <button v-for="p in PRESETS" :key="p.label" type="button" :class="btnClass.outline" class="h-7! px-2.5! text-xs" @click="preset(p)">{{ p.label }}</button>
      </div>

      <div class="grid gap-2">
        <label for="s3-name" :class="label">Название</label>
        <input id="s3-name" v-model="form.name" required placeholder="Yandex Cloud" :class="inputClass" :aria-invalid="!!errors.name" />
        <p v-for="m in errors.name" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-[1fr_10rem]">
        <div class="grid content-start gap-2">
          <label for="s3-endpoint" :class="label">Адрес (endpoint)</label>
          <input id="s3-endpoint" v-model="form.endpoint" placeholder="https://storage.yandexcloud.net" spellcheck="false" :class="inputClass" class="font-mono" :aria-invalid="!!errors.endpoint" />
          <p :class="hint">Пусто — Amazon S3.</p>
          <p v-for="m in errors.endpoint" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid content-start gap-2">
          <label for="s3-region" :class="label">Регион</label>
          <input id="s3-region" v-model="form.region" placeholder="ru-central1" spellcheck="false" :class="inputClass" class="font-mono" />
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div class="grid content-start gap-2">
          <label for="s3-bucket" :class="label">Бакет</label>
          <input id="s3-bucket" v-model="form.bucket" required placeholder="my-backups" spellcheck="false" :class="inputClass" class="font-mono" :aria-invalid="!!errors.bucket" />
          <p v-for="m in errors.bucket" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid content-start gap-2">
          <label for="s3-prefix" :class="label">Каталог в бакете</label>
          <input id="s3-prefix" v-model="form.prefix" placeholder="systemkeeper" spellcheck="false" :class="inputClass" class="font-mono" :aria-invalid="!!errors.prefix" />
          <p :class="hint">Необязательно.</p>
          <p v-for="m in errors.prefix" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div class="grid content-start gap-2">
          <label for="s3-key" :class="label">Ключ доступа (Access key ID)</label>
          <input id="s3-key" v-model="form.key" required autocomplete="off" spellcheck="false" :class="inputClass" class="font-mono" :aria-invalid="!!errors.key" />
          <p v-for="m in errors.key" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div class="grid content-start gap-2">
          <label for="s3-secret" :class="label">Секретный ключ</label>
          <input
            id="s3-secret"
            v-model="form.secret"
            type="password"
            autocomplete="new-password"
            :required="!storage"
            :placeholder="storage?.s3?.has_secret ? 'Сохранён. Введите новый, чтобы заменить' : ''"
            :class="inputClass"
            :aria-invalid="!!errors.secret"
          />
          <p v-for="m in errors.secret" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
      </div>

      <label class="flex items-center gap-2 text-sm">
        <input v-model="form.path_style" type="checkbox" class="size-4 accent-primary" />
        Path-style адреса (адрес/бакет вместо бакет.адрес) — нужно для MinIO и некоторых провайдеров
      </label>

      <p :class="hint">Ключи хранятся в SystemKeeper в зашифрованном виде. После сохранения подключение будет проверено.</p>
      <p v-if="formError" class="text-sm text-destructive">{{ formError }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" :class="btnClass.outline" @click="emit('close')">Отмена</button>
        <button type="submit" :disabled="saving" :class="btnClass.primary">{{ storage ? 'Сохранить' : 'Подключить' }}</button>
      </div>
    </form>
  </Modal>
</template>
