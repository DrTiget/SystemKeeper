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
  type: 'local' | 's3' | 'yandex_disk'
  s3: { endpoint: string | null; region: string | null; bucket: string; prefix: string; path_style: boolean; key: string; has_secret: boolean } | null
  /** Яндекс Диск: папка; сам токен наружу не отдаётся */
  yandex_disk: { folder: string; has_token: boolean; parallel: number } | null
  is_default: boolean
  disk: { total: number; free: number } | null
  used: number
  backups_count: number
  sources?: StorageSource[]
}

export const STORAGE_TYPE_LABELS: Record<BackupStorage['type'], string> = { local: 'Диск SystemKeeper', s3: 'S3', yandex_disk: 'Яндекс Диск' }

/** Синхронизация хранилища — GET/POST /storages/{id}/sync: копирование сюда бекапов из других хранилищ. */
export interface StorageSyncState {
  /** Бекапы, которых здесь нет, но они есть в других хранилищах */
  pending: { backups: number; size: number }
  last: {
    id: number
    status: 'queued' | 'running' | 'success' | 'failed'
    add_to_sources: boolean
    total: number
    done: number
    failed: number
    bytes_total: number
    bytes_done: number
    current: string | null
    error: string | null
    started_at: string | null
    finished_at: string | null
    created_at: string
  } | null
}
