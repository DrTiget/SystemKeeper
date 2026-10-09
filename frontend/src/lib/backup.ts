import type { BackupStatus, BackupType, RestoreStatus } from '@/api/types'
import { api } from '@/api/client'

export const typeLabel: Record<BackupType, string> = { files: 'Файлы', mysql: 'MySQL', postgres: 'PostgreSQL' }

const STATUS_BADGE = {
  pending: { tone: 'neutral', label: 'В очереди' },
  running: { tone: 'info', label: 'Выполняется' },
  success: { tone: 'success', label: 'Успешно' },
  failed: { tone: 'danger', label: 'Ошибка' },
} as const satisfies Record<BackupStatus, { tone: string; label: string }>
export const statusBadge = (s: BackupStatus) => STATUS_BADGE[s]

export const isActive = (s: BackupStatus | undefined | null) => s === 'pending' || s === 'running'

/**
 * Скачивание файла бекапа по временной подписанной ссылке (POST /backups/{id}/download-link, ~5 мин):
 * браузер сам стримит файл обычным переходом, без blob в памяти и без Bearer-заголовка.
 * Из ссылки берём только путь с подписью: хост в ней может быть внутренним (за прокси).
 */
export async function downloadBackup(id: number, fallbackName: string | null): Promise<void> {
  const { data } = await api<{ data: { url: string; expires_at: string } }>(`/backups/${id}/download-link`, { method: 'POST' })
  const link = new URL(data.url, window.location.origin)
  const a = document.createElement('a')
  a.href = link.pathname + link.search
  a.download = fallbackName ?? `backup-${id}.zst`
  document.body.appendChild(a)
  a.click()
  a.remove()
}

const RESTORE_BADGE = {
  pending: { tone: 'neutral', label: 'Проверка в очереди' },
  running: { tone: 'info', label: 'Проверяется' },
  ok: { tone: 'success', label: 'Восстановление ок' },
  failed: { tone: 'danger', label: 'Восстановление не удалось' },
  skipped: { tone: 'warning', label: 'Проверка пропущена' },
} as const satisfies Record<RestoreStatus, { tone: string; label: string }>
export const restoreBadge = (s: RestoreStatus) => RESTORE_BADGE[s]
export const isRestoreActive = (s: RestoreStatus | null | undefined) => s === 'pending' || s === 'running'
