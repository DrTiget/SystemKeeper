<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { BackupStorage } from '@/api/storages'
import Modal from '@/components/Modal.vue'
import { btnClass, inputClass } from '@/lib/ui'

const props = defineProps<{ storage: BackupStorage | null }>()
const emit = defineEmits<{ close: []; saved: [storage: BackupStorage] }>()

const form = reactive({
  name: props.storage?.name ?? 'Яндекс Диск',
  folder: props.storage?.yandex_disk?.folder ?? 'disk:/SystemKeeper',
  parallel: props.storage?.yandex_disk?.parallel ?? 4,
  token: '',
})
// ID своего приложения Яндекс OAuth — только чтобы собрать ссылку на получение токена, не сохраняется
const clientId = ref('')
const tokenLink = computed(() =>
  /^[0-9a-f]{32}$/i.test(clientId.value.trim()) ? `https://oauth.yandex.ru/authorize?response_type=token&client_id=${clientId.value.trim()}` : null,
)
const errors = ref<Record<string, string[]>>({})
const formError = ref('')
const saving = ref(false)

async function save() {
  saving.value = true
  errors.value = {}
  formError.value = ''
  const body: Record<string, unknown> = { name: form.name, folder: form.folder.trim() || null, parallel: Number(form.parallel) }
  if (form.token || !props.storage) body.token = form.token.trim()
  try {
    const res = props.storage
      ? await api<{ data: BackupStorage }>(`/storages/${props.storage.id}`, { method: 'PATCH', body })
      : await api<{ data: BackupStorage }>('/storages', { method: 'POST', body: { ...body, type: 'yandex_disk' } })
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
  <Modal wide :title="storage ? 'Изменить Яндекс Диск' : 'Подключить Яндекс Диск'" @close="emit('close')">
    <form class="grid gap-4" @submit.prevent="save">
      <div class="grid gap-2">
        <label for="yd-name" :class="label">Название</label>
        <input id="yd-name" v-model="form.name" required :class="inputClass" :aria-invalid="!!errors.name" />
        <p v-for="m in errors.name" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>

      <div class="grid gap-2">
        <label for="yd-folder" :class="label">Папка на Диске</label>
        <input id="yd-folder" v-model="form.folder" placeholder="disk:/SystemKeeper" spellcheck="false" :class="inputClass" class="font-mono" :aria-invalid="!!errors.folder" />
        <p :class="hint">Создастся сама. Для токена с доступом только к папке приложения укажите <code>app:/</code>.</p>
        <p v-for="m in errors.folder" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>

      <div class="grid gap-2">
        <label for="yd-parallel" :class="label">Одновременных загрузок</label>
        <input id="yd-parallel" v-model.number="form.parallel" type="number" min="1" max="10" :class="inputClass" class="w-24" :aria-invalid="!!errors.parallel" />
        <p :class="hint">Сколько файлов от 8 МБ Диск скачивает с SystemKeeper (или из S3) одновременно, 1–10. Больше — быстрее, пока хватает исходящего канала сервера.</p>
        <p v-for="m in errors.parallel" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>

      <details class="rounded-lg border p-3 text-sm" :open="!storage">
        <summary class="cursor-pointer font-medium">Как получить токен</summary>
        <ol class="mt-2 grid list-decimal gap-1.5 pl-5 text-muted-foreground">
          <li>
            Создайте приложение на <a href="https://oauth.yandex.ru/client/new" target="_blank" rel="noopener" class="text-primary hover:underline">oauth.yandex.ru</a>:
            платформа «Веб-сервисы», Redirect URI — <code>https://oauth.yandex.ru/verification_code</code>.
          </li>
          <li>
            Права «Яндекс.Диск REST API»: «Доступ к информации о Диске» и либо «Доступ к папке приложения на Диске», либо «Чтение всего Диска» и «Запись в любом месте на Диске».
          </li>
          <li>
            Вставьте ClientID приложения:
            <input v-model="clientId" placeholder="ClientID" spellcheck="false" :class="inputClass" class="mt-1 h-8! font-mono text-xs" />
          </li>
          <li>
            <a v-if="tokenLink" :href="tokenLink" target="_blank" rel="noopener" class="text-primary hover:underline">Получите токен</a>
            <template v-else>Откройте ссылку на получение токена (появится после ClientID)</template>,
            разрешите доступ и скопируйте токен в поле ниже.
          </li>
        </ol>
      </details>

      <div class="grid gap-2">
        <label for="yd-token" :class="label">OAuth-токен</label>
        <input
          id="yd-token"
          v-model="form.token"
          type="password"
          autocomplete="off"
          :required="!storage"
          :placeholder="storage?.yandex_disk?.has_token ? 'Сохранён. Введите новый, чтобы заменить' : 'y0_…'"
          :class="inputClass"
          class="font-mono"
          :aria-invalid="!!errors.token"
        />
        <p :class="hint">Хранится зашифрованным и наружу не отдаётся.</p>
        <p v-for="m in errors.token" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>

      <p v-if="formError" class="text-sm text-destructive">{{ formError }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" :class="btnClass.outline" @click="emit('close')">Отмена</button>
        <button type="submit" :class="btnClass.primary" :disabled="saving">{{ storage ? 'Сохранить' : 'Подключить' }}</button>
      </div>
    </form>
  </Modal>
</template>
