export interface Retention {
  storage_id: number | null
  keep_count: number
  /** байт; null — без ограничения */
  max_size: number | null
}

export interface StorageSource {
  type: 'server' | 'database'
  id: number
  name: string
  used: number
  backups_count: number
  keep_count: number
  max_size: number | null
}

export interface BackupStorage {
  id: number
  name: string
  type: 'local'
  is_default: boolean
  disk: { total: number; free: number } | null
  used: number
  backups_count: number
  sources?: StorageSource[]
}

export const STORAGE_TYPE_LABELS: Record<BackupStorage['type'], string> = { local: 'Диск SystemKeeper' }
