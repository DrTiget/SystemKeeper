import type { AlertChannelType, AlertTargetType } from '@/api/types'

export const EVENTS: Record<string, string> = {
  'incident.opened': 'Инцидент открыт',
  'incident.resolved': 'Инцидент закрыт',
  'backup.failed': 'Бекап не удался',
  'backup.succeeded': 'Бекап выполнен',
  'storage_copy.failed': 'Копия в хранилище не загружена',
  'backup.restore_failed': 'Тестовое восстановление не удалось',
  'ssl.expiring': 'SSL-сертификат скоро истечёт',
  'metric.threshold_exceeded': 'Порог метрики сервера превышен',
  'metric.threshold_recovered': 'Метрика сервера снова в норме',
}
export const eventLabel = (e: string) => EVENTS[e] ?? e

export const CHANNEL_TYPES: Record<AlertChannelType, string> = {
  telegram: 'Telegram',
  email: 'Email',
  webpush: 'Web Push',
  mobile: 'Мобильное приложение',
}

export const TARGET_TYPES: Record<AlertTargetType, { label: string; list: string; field: string }> = {
  monitor: { label: 'Монитор', list: '/monitors', field: 'name' },
  backup_job: { label: 'Задание бекапа', list: '/backup-jobs', field: 'name' },
  server: { label: 'Сервер', list: '/servers', field: 'name' },
  storage_target: { label: 'Хранилище', list: '/storage-targets', field: 'name' },
}

export const DELIVERY_STATUS = {
  pending: { tone: 'info', label: 'В очереди' },
  sent: { tone: 'success', label: 'Отправлено' },
  failed: { tone: 'danger', label: 'Ошибка' },
  skipped: { tone: 'neutral', label: 'Пропущено' },
} as const

export function urlBase64ToUint8Array(b64: string): Uint8Array<ArrayBuffer> {
  const pad = '='.repeat((4 - (b64.length % 4)) % 4)
  const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'))
  const out = new Uint8Array(new ArrayBuffer(raw.length))
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i)
  return out
}
