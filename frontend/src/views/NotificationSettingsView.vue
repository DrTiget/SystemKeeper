<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/api/client'
import type { NotificationSettings } from '@/api/settings'
import Icon from '@/components/Icon.vue'
import MailSettingsForm from '@/components/settings/MailSettingsForm.vue'
import TelegramSettingsForm from '@/components/settings/TelegramSettingsForm.vue'

const settings = ref<NotificationSettings | null>(null)
const loadError = ref('')

onMounted(async () => {
  try {
    settings.value = (await api<{ data: NotificationSettings }>('/settings/notifications')).data
  } catch {
    loadError.value = 'Не удалось загрузить настройки'
  }
})
</script>

<template>
  <div class="flex max-w-3xl flex-col gap-6 p-4">
    <header class="space-y-0.5">
      <h2 class="text-xl font-semibold tracking-tight">Настройки → Уведомления</h2>
      <p class="text-sm text-muted-foreground">Как SystemKeeper отправляет письма и подключается к Telegram. Сами каналы и правила — в разделе «Алерты».</p>
    </header>

    <p v-if="loadError" class="text-sm text-destructive">{{ loadError }}</p>
    <p v-else-if="!settings" class="flex items-center gap-2 text-sm text-muted-foreground"><Icon name="loader" class="animate-spin" />Загрузка…</p>
    <template v-else>
      <MailSettingsForm :settings="settings.mail" @saved="settings.mail = $event.mail" />
      <TelegramSettingsForm :settings="settings.telegram" @saved="settings.telegram = $event.telegram" />
    </template>
  </div>
</template>
