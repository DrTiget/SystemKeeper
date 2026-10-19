<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { type AlertChannel, CHANNEL_TYPES, type ChannelType } from '@/api/alerts'
import { api, ApiError } from '@/api/client'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import type { NotificationSettings } from '@/api/settings'
import { btnClass, inputClass } from '@/lib/ui'
import { useAuthStore } from '@/stores/auth'

/** Создание и изменение канала. Секреты (токен бота, секрет вебхука) не возвращаются API: пусто — оставить прежние. */
const props = defineProps<{ channel: AlertChannel | null }>()
const emit = defineEmits<{ close: []; saved: [channel: AlertChannel] }>()

const editing = props.channel
const form = reactive({
  name: editing?.name ?? '',
  type: (editing?.type ?? 'telegram') as ChannelType,
  enabled: editing?.enabled ?? true,
  bot_token: '',
  chat_id: editing?.config.chat_id ?? '',
  recipients: editing?.config.recipients?.join(', ') ?? '',
  url: editing?.config.url ?? '',
  secret: '',
})
const errors = ref<Record<string, string[]>>({})
const formError = ref('')
const saving = ref(false)
const auth = useAuthStore()
// Настроена ли почта. Настройки видит только администратор: остальным подсказка не нужна, а сервер всё равно ответит 422
const mailConfigured = ref(true)
onMounted(async () => {
  if (!auth.user?.is_admin) return
  try {
    mailConfigured.value = (await api<{ data: NotificationSettings }>('/settings/notifications')).data.mail.configured
  } catch {
    // подсказка необязательна
  }
})
const keepsSecret = computed(() => !!editing && editing.type === form.type)

async function save() {
  saving.value = true
  errors.value = {}
  formError.value = ''
  const config: Record<string, unknown> = {}
  if (form.type === 'telegram') {
    config.chat_id = form.chat_id.trim()
    if (form.bot_token.trim() || !keepsSecret.value) config.bot_token = form.bot_token.trim()
  } else if (form.type === 'email') {
    config.recipients = form.recipients.split(/[,;\s]+/).filter(Boolean)
  } else {
    config.url = form.url.trim()
    if (form.secret.trim()) config.secret = form.secret.trim()
  }
  const body = { name: form.name.trim() || CHANNEL_TYPES[form.type].label, type: form.type, enabled: form.enabled, config }
  try {
    const res = (await api<{ data: AlertChannel }>(editing ? `/alert-channels/${editing.id}` : '/alert-channels', { method: editing ? 'PATCH' : 'POST', body })).data
    emit('saved', res)
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) errors.value = e.errors
    else formError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

const err = (k: string) => errors.value[k] ?? errors.value[`config.${k}`]
const field = 'grid gap-1.5'
</script>

<template>
  <Modal wide :title="editing ? 'Канал уведомлений' : 'Новый канал'" @close="emit('close')">
    <form class="grid gap-5" @submit.prevent="save">
      <div role="radiogroup" class="grid gap-2 sm:grid-cols-3">
        <label v-for="(t, key) in CHANNEL_TYPES" :key="key" class="grid cursor-pointer gap-1 rounded-lg border p-3 has-checked:border-primary has-checked:bg-accent/50">
          <input v-model="form.type" type="radio" :value="key" class="sr-only" />
          <span class="flex items-center gap-2 text-sm font-medium"><Icon :name="t.icon" class="size-4" />{{ t.label }}</span>
          <span class="text-xs text-muted-foreground">{{ t.hint }}</span>
        </label>
      </div>
      <p v-for="m in errors.type" :key="m" class="-mt-3 text-sm text-destructive">
        {{ m }}
        <RouterLink v-if="auth.user?.is_admin && form.type === 'email'" to="/settings/notifications" class="font-medium underline" @click="emit('close')">Открыть настройки</RouterLink>
      </p>

      <div :class="field">
        <label for="c-name" class="text-sm font-medium">Название</label>
        <input id="c-name" v-model="form.name" :placeholder="form.type === 'telegram' ? 'Дежурный чат' : form.type === 'email' ? 'Админам на почту' : 'Slack #alerts'" :class="inputClass" />
      </div>

      <template v-if="form.type === 'telegram'">
        <div :class="field">
          <label for="c-token" class="text-sm font-medium">Токен бота</label>
          <input id="c-token" v-model="form.bot_token" type="password" autocomplete="off" :required="!keepsSecret" :placeholder="keepsSecret ? 'сохранён — введите, чтобы заменить' : '123456:ABC-DEF…'" spellcheck="false" :class="inputClass" class="font-mono" :aria-invalid="!!err('bot_token')" />
          <p class="text-xs text-muted-foreground">Создайте бота у @BotFather и добавьте его в нужный чат или группу.</p>
          <p v-for="m in err('bot_token')" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div :class="field">
          <label for="c-chat" class="text-sm font-medium">ID чата</label>
          <input id="c-chat" v-model="form.chat_id" required placeholder="-1001234567890" spellcheck="false" :class="inputClass" class="font-mono" :aria-invalid="!!err('chat_id')" />
          <p class="text-xs text-muted-foreground">Для групп начинается с минуса. Узнать можно у @userinfobot или @getidsbot.</p>
          <p v-for="m in err('chat_id')" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <p class="text-xs text-muted-foreground">
          Если api.telegram.org недоступен с сервера, задайте адрес Bot API или прокси в
          <RouterLink v-if="auth.user?.is_admin" to="/settings/notifications" class="font-medium text-primary hover:underline" @click="emit('close')">Настройки → Уведомления</RouterLink>
          <template v-else>настройках уведомлений (нужен администратор)</template>.
        </p>
      </template>

      <div v-else-if="form.type === 'email'" :class="field">
        <label for="c-rcpt" class="text-sm font-medium">Адреса</label>
        <input id="c-rcpt" v-model="form.recipients" required placeholder="admin@example.com, ops@example.com" spellcheck="false" :class="inputClass" :aria-invalid="!!err('recipients')" />
        <p class="text-xs text-muted-foreground">Через запятую. Письма уходят через почту, настроенную в SystemKeeper.</p>
        <p v-if="!mailConfigured" class="rounded-md border border-amber-500/40 bg-amber-500/10 p-2.5 text-sm">
          Почта не настроена, письма никуда не уйдут.
          <RouterLink to="/settings/notifications" class="font-medium text-primary hover:underline" @click="emit('close')">Настроить в Настройки → Уведомления</RouterLink>
        </p>
        <p v-for="m in err('recipients')" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>

      <template v-else>
        <div :class="field">
          <label for="c-url" class="text-sm font-medium">Адрес</label>
          <input id="c-url" v-model="form.url" type="url" required placeholder="https://hooks.slack.com/services/…" spellcheck="false" :class="inputClass" class="font-mono" :aria-invalid="!!err('url')" />
          <p class="text-xs text-muted-foreground">POST с JSON: правило, серьёзность, объект, сообщение, статус (firing / resolved).</p>
          <p v-for="m in err('url')" :key="m" class="text-sm text-destructive">{{ m }}</p>
        </div>
        <div :class="field">
          <label for="c-secret" class="text-sm font-medium">Секрет подписи</label>
          <input id="c-secret" v-model="form.secret" type="password" autocomplete="off" :placeholder="editing?.config.has_secret && keepsSecret ? 'сохранён — введите, чтобы заменить' : 'необязательно'" spellcheck="false" :class="inputClass" class="font-mono" />
          <p class="text-xs text-muted-foreground">Если задан — в заголовке X-Signature придёт HMAC-SHA256 тела, чтобы проверить отправителя.</p>
        </div>
      </template>

      <label class="flex items-center gap-2 text-sm"><input v-model="form.enabled" type="checkbox" class="size-4 accent-primary" />Канал включён</label>

      <p v-if="formError" class="text-sm text-destructive">{{ formError }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" :class="btnClass.outline" @click="emit('close')">Отмена</button>
        <button type="submit" :class="btnClass.primary" :disabled="saving">{{ editing ? 'Сохранить' : 'Добавить канал' }}</button>
      </div>
    </form>
  </Modal>
</template>
