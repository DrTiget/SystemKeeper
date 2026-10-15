import type { ProjectRef } from './projects'
import type { Retention } from './storages'
export type AuthType = 'key' | 'password'

export interface Server {
  id: number
  name: string
  project: ProjectRef | null
  description: string | null
  host: string
  port: number
  username: string
  auth_type: AuthType
  has_private_key: boolean
  has_passphrase: boolean
  has_password: boolean
  public_key: string | null
  host_fingerprint: string | null
  last_check: { status: 'ok' | 'failed' | null; error: string | null; checked_at: string | null }
  backup?: { enabled: boolean; schedule: string | null; paths_count: number; next_run_at: string | null }
  created_at: string
}

export interface CheckResult {
  status: 'ok' | 'failed'
  error: string | null
  output: string | null
  server: Server
}

export interface BackupSettings {
  enabled: boolean
  paths: string[]
  excludes: string[]
  schedule: string
  timezone: string
  next_run_at: string | null
  pre_command: string | null
  pre_command_timeout: number
  pre_command_continue_on_error: boolean
  retention: Retention
}

export interface PreCommandTemplate {
  key: string
  group: string
  title: string
  description: string
  command: string
}

export interface FileEntry {
  name: string
  path: string
  type: 'dir' | 'file' | 'other'
  link: boolean
  size: number | null
}

export interface DirListing {
  path: string
  entries: FileEntry[]
  truncated: boolean
}

/** Что бекапить — POST /servers/{id}/discover */
export interface Discovery {
  info: Record<string, string>
  paths: { path: string; category: string; reasons: string[]; inside: string | null; recommended: boolean; size: number | null }[]
  excludes: { pattern: string; reason: string }[]
  databases: { engine: string; where: 'local' | 'docker'; container: string | null; detail: string; supported: boolean; added: boolean }[]
}
