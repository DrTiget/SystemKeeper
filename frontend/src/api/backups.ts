import { ApiError, getToken } from './client'
import type { ProjectRef } from './projects'

export type BackupStatus = 'queued' | 'running' | 'success' | 'failed'

export interface Backup {
  id: number
  source_type: 'server' | 'database'
  source_id: number
  /** в списках по проекту */
  source?: { type: 'server' | 'database'; id: number; name: string } | null
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

/**
 * Бекап для отдельной страницы — GET /backups/{id}.
 * Поля сверх Backup необязательные: API отдаёт их по мере готовности, страница пока их не выводит.
 */
export interface BackupDetail extends Backup {
  /** Сервер или база данных, а также проект — для ссылок и хлебных крошек */
  source?: { type: 'server' | 'database'; id: number; name: string; project?: ProjectRef | null } | null
  /** Копии по местам хранения: диск SystemKeeper, S3… */
  copies: BackupCopy[]
  /** Каталог бекапа внутри хранилища */
  path?: string | null
  /** Файлы с контрольными суммами */
  files: { name: string; size: number; sha256?: string | null; uncompressed_size?: number }[]
  /** Подробности, собранные при снятии; старые бекапы — без них */
  meta: BackupMeta
  /** Что бекапилось — снимок настроек на момент запуска */
  settings?: {
    /** Сервер: каталоги, исключения и команда перед бекапом */
    paths?: string[]
    excludes?: string[]
    pre_command?: string | null
    /** База данных: базы, таблицы без данных и пропущенные, параметры дампа */
    databases?: string[]
    schema_only_tables?: string[]
    exclude_tables?: string[]
    options?: Record<string, unknown>
  } | null
  /** Источник на момент бекапа: адрес, версия СУБД или ОС */
  source_info?: { host?: string | null; version?: string | null } | null
  /** Хранение: место среди хранимых копий источника (1 — самый новый) и его правила хранения */
  retention?: { position: number; keep_count: number; max_size: number | null } | null
}

export interface BackupMeta {
  host?: { hostname: string | null; os: string | null; kernel: string | null; tools: Record<string, string> }
  source?:
    | { type: 'server'; name: string; address: string; paths: string[]; excludes: string[] }
    | {
        type: 'database'
        name: string
        engine: 'mysql' | 'postgres'
        version: string | null
        address: string
        via: 'ssh' | 'direct'
        server: string | null
        docker_container: string | null
        options: Record<string, string | boolean>
        exclude_tables: string[]
        schema_only_tables: string[]
      }
  pre_command?: { command: string; exit_code: number | null; duration_ms: number; output: string; continued_on_error: boolean }
  archive?: { format: string; uncompressed_size: number | null; duration_ms: number; warnings: string | null }
  databases?: { name: string; size: number | null; file: string; dump_size: number; uncompressed_size: number | null; duration_ms: number; status: 'success' | 'failed'; error: string | null }[]
}

export interface BackupCopy {
  storage_id: number
  name: string | null
  type: 'local' | 's3' | null
  status: 'success' | 'failed'
  error: string | null
}

export const isActive = (b: Backup) => b.status === 'queued' || b.status === 'running'

export const BACKUP_STATUS: Record<BackupStatus, { label: string; dot: string }> = {
  queued: { label: 'В очереди', dot: 'bg-muted-foreground' },
  running: { label: 'Выполняется', dot: 'bg-amber-500 animate-pulse' },
  success: { label: 'Готово', dot: 'bg-emerald-500' },
  failed: { label: 'Ошибка', dot: 'bg-destructive' },
}

export const backupTrigger = (b: Backup) => (b.trigger === 'manual' ? `вручную${b.user ? ', ' + b.user.name : ''}` : 'по расписанию')

/** Длительность: у идущего бекапа — от старта до текущего момента. */
export function backupDuration(b: Backup): string {
  if (!b.started_at) return ''
  const s = Math.round(((b.finished_at ? new Date(b.finished_at) : new Date()).getTime() - new Date(b.started_at).getTime()) / 1000)
  if (s < 60) return `${s} с`
  if (s < 3600) return `${Math.floor(s / 60)} мин ${s % 60} с`
  return `${Math.floor(s / 3600)} ч ${Math.floor((s % 3600) / 60)} мин`
}

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
