import { api } from './client'

/** Версия SystemKeeper и обновление в один клик — /api/v1/system. */
export type UpdaterStatus = 'idle' | 'pending' | 'waiting' | 'backup' | 'pulling' | 'restarting' | 'done' | 'failed' | 'rolled_back'

export interface SystemVersion {
  current: string
  latest: { version: string; name: string; notes: string; url: string; published_at: string | null } | null
  update_available: boolean
  checked_at: string | null
  check_error: string | null
  /** Идущие бекапы и синхронизации — пока они есть, обновиться нельзя */
  busy: { backups: number; syncs: number }
  updater: {
    available: boolean
    status: UpdaterStatus
    message: string | null
    from: string | null
    to: string | null
    updated_at: string | null
  }
}

export const ACTIVE_STATUSES: UpdaterStatus[] = ['pending', 'waiting', 'backup', 'pulling', 'restarting']

export const getVersion = () => api<{ data: SystemVersion }>('/system/version').then((r) => r.data)
export const checkUpdates = () => api<{ data: SystemVersion }>('/system/check', { method: 'POST' }).then((r) => r.data)
export const startUpdate = () => api<{ data: SystemVersion }>('/system/update', { method: 'POST' }).then((r) => r.data)

/** Версия в «Что нового»: notes — Markdown; is_new — ещё не установлена */
export interface ChangelogEntry {
  version: string
  name: string
  notes: string
  url: string | null
  published_at: string | null
  is_new: boolean
  is_current: boolean
}

export const getChangelog = () => api<{ data: ChangelogEntry[] }>('/system/changelog').then((r) => r.data)
