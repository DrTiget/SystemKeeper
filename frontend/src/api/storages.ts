export interface Retention {
  /** куда сохранять; пусто — в место по умолчанию */
  storage_ids: number[]
  keep_count: number
  /** байт; null — без ограничения */
  max_size: number | null
}

export interface StorageSource {
  type: 'server' | 'database'
  id: number
  name: string
  project: { id: number; name: string; group: { id: number; name: string } | null } | null
  used: number
  backups_count: number
  keep_count: number
  max_size: number | null
  storage_ids: number[]
}

export interface BackupStorage {
  id: number
  name: string
  type: 'local' | 's3'
  s3: { endpoint: string | null; region: string | null; bucket: string; prefix: string; path_style: boolean; key: string; has_secret: boolean } | null
  is_default: boolean
  disk: { total: number; free: number } | null
  used: number
  backups_count: number
  sources?: StorageSource[]
}

export const STORAGE_TYPE_LABELS: Record<BackupStorage['type'], string> = { local: 'Диск SystemKeeper', s3: 'S3' }
