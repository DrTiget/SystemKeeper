import { ApiError, getToken } from './client'

export type BackupStatus = 'queued' | 'running' | 'success' | 'failed'

export interface Backup {
  id: number
  source_type: 'server' | 'database'
  source_id: number
  trigger: 'manual' | 'schedule'
  status: BackupStatus
  user: { id: number; name: string } | null
  started_at: string | null
  finished_at: string | null
  files: { name: string; size: number }[]
  size: number | null
  log: { at: string; message: string }[]
  error: string | null
  created_at: string
}

export const isActive = (b: Backup) => b.status === 'queued' || b.status === 'running'

/** Скачивание файла бекапа: нужен Bearer-токен, поэтому через fetch, а не обычной ссылкой. */
export async function downloadBackupFile(b: Backup, name: string): Promise<void> {
  const res = await fetch(`/api/v1/backups/${b.id}/files/${encodeURIComponent(name)}`, {
    headers: { Authorization: `Bearer ${getToken()}` },
  })
  if (!res.ok) throw new ApiError(res.status, 'Не удалось скачать файл')
  const url = URL.createObjectURL(await res.blob())
  const a = document.createElement('a')
  a.href = url
  a.download = name
  a.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
