<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/api/client'
import Icon from '@/components/Icon.vue'
import { urlBase64ToUint8Array } from '@/lib/alerts'
import { btnClass, errorMessage } from '@/lib/format'

const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
const secure = window.isSecureContext
const subscribed = ref(false)
const busy = ref(false)
const error = ref('')
const denied = ref(supported && Notification.permission === 'denied')

async function registration() {
  return (await navigator.serviceWorker.getRegistration('/sw.js')) ?? (await navigator.serviceWorker.register('/sw.js'))
}

onMounted(async () => {
  if (!supported || !secure) return
  try {
    subscribed.value = !!(await (await registration()).pushManager.getSubscription())
  } catch {
    /* состояние неизвестно — покажем кнопку «Включить» */
  }
})

async function enable() {
  busy.value = true
  error.value = ''
  try {
    const perm = await Notification.requestPermission()
    denied.value = perm === 'denied'
    if (perm !== 'granted') {
      error.value = 'Разрешение на уведомления не выдано. Разрешите их для этого сайта в настройках браузера.'
      return
    }
    const key = (await api<{ data: { public_key: string } }>('/push/vapid-public-key')).data.public_key
    const reg = await registration()
    await navigator.serviceWorker.ready
    const sub = (await reg.pushManager.getSubscription()) ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(key) }))
    await api('/push-subscriptions', { method: 'POST', body: sub.toJSON() })
    subscribed.value = true
  } catch (e) {
    error.value = (e as { status?: number }).status === 503 ? 'На сервере не настроены VAPID-ключи (php artisan alerts:vapid-keys).' : e instanceof DOMException ? `Браузер отклонил подписку: ${e.message}` : errorMessage(e, 'Не удалось включить уведомления')
  } finally {
    busy.value = false
  }
}

async function disable() {
  busy.value = true
  error.value = ''
  try {
    const sub = await (await registration()).pushManager.getSubscription()
    if (sub) {
      await api('/push-subscriptions', { method: 'DELETE', body: { endpoint: sub.endpoint } })
      await sub.unsubscribe()
    }
    subscribed.value = false
  } catch (e) {
    error.value = errorMessage(e, 'Не удалось отключить уведомления')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-2 rounded-xl border bg-card p-4">
    <div class="flex flex-wrap items-center gap-3">
      <Icon name="bell" class="text-muted-foreground" />
      <div class="min-w-0 flex-1">
        <div class="text-sm font-medium">Уведомления в этом браузере</div>
        <p class="text-xs text-muted-foreground">Канал «Web Push» шлёт сообщения во все подписанные браузеры, даже когда вкладка закрыта.</p>
      </div>
      <button v-if="subscribed" :class="btnClass.outline" :disabled="busy" @click="disable"><Icon v-if="busy" name="loader" class="animate-spin" />Отключить</button>
      <button v-else :class="btnClass.default" :disabled="busy || !supported || !secure || denied" @click="enable"><Icon v-if="busy" name="loader" class="animate-spin" />Включить уведомления в этом браузере</button>
    </div>
    <p v-if="!secure" class="text-sm text-warning">Push работает только на https или localhost. Откройте сервис по https-адресу.</p>
    <p v-else-if="!supported" class="text-sm text-warning">Этот браузер не поддерживает Web Push.</p>
    <p v-else-if="denied" class="text-sm text-warning">Уведомления для этого сайта заблокированы. Разрешите их в настройках браузера и обновите страницу.</p>
    <p v-if="subscribed" class="text-sm text-success">Браузер подписан на уведомления.</p>
    <p v-if="error" role="alert" class="text-sm text-destructive">{{ error }}</p>
  </div>
</template>
