import type { BackupStatus, BackupType } from '@/api/types'
import { ApiError, getToken } from '@/api/client'

export const typeLabel: Record<BackupType, string> = { files: 'Файлы', mysql: 'MySQL', postgres: 'PostgreSQL' }

const STATUS_BADGE = {
  pending: { tone: 'neutral', label: 'В очереди' },
  running: { tone: 'info', label: 'Выполняется' },
  success: { tone: 'success', label: 'Успешно' },
  failed: { tone: 'danger', label: 'Ошибка' },
} as const satisfies Record<BackupStatus, { tone: string; label: string }>
export const statusBadge = (s: BackupStatus) => STATUS_BADGE[s]

export const isActive = (s: BackupStatus | undefined | null) => s === 'pending' || s === 'running'

/** Скачивание файла бекапа: нужен Bearer-токен, поэтому через fetch + blob. */
export async function downloadBackup(id: number, fallbackName: string | null): Promise<void> {
  const res = await fetch(`/api/v1/backups/${id}/download`, { headers: { Authorization: `Bearer ${getToken()}`, Accept: 'application/json' } })
  if (!res.ok) throw new ApiError(res.status, 'Не удалось скачать')
  const url = URL.createObjectURL(await res.blob())
  const a = document.createElement('a')
  a.href = url
  a.download = fallbackName ?? `backup-${id}.zst`
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}
