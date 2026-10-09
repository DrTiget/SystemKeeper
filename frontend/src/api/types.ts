export interface Paginated<T> {
  data: T[]
  links: Record<string, string | null>
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export interface SshKey {
  id: number
  name: string
  type: 'ed25519' | 'rsa'
  public_key: string
  fingerprint: string
  has_passphrase: boolean
  servers_count?: number
  created_at: string
  updated_at: string
}

export interface Server {
  id: number
  name: string
  host: string
  port: number
  username: string
  auth_type: 'key' | 'password'
  ssh_key_id: number | null
  ssh_key: Pick<SshKey, 'id' | 'name' | 'type' | 'fingerprint'> | null
  has_password: boolean
  description: string | null
  last_checked_at: string | null
  last_check_status: 'ok' | 'failed' | null
  last_check_error: string | null
  host_fingerprint: string | null
  created_at: string
  updated_at: string
}

export interface CheckResult {
  status: 'ok' | 'failed'
  error: string | null
  output: { exit_code: number; stdout: string; stderr: string } | null
  checked_at: string
  server: Server
}

export interface StorageTarget {
  id: number
  name: string
  type: 'local' | 'yandex_disk' | 's3' | 'sftp'
  base_path: string | null
  enabled: boolean
  has_token: boolean
  has_secret: boolean
  server_id: number | null
  server: { id: number; name: string; host: string; port: number } | null
  endpoint: string | null
  region: string | null
  bucket: string | null
  prefix: string | null
  access_key: string | null
  path_style: boolean | null
  storage_class: string | null
  last_checked_at: string | null
  last_check_status: 'ok' | 'failed' | null
  last_check_error: string | null
  created_at: string
  updated_at: string
}

export interface StorageCheck {
  status: 'ok' | 'failed'
  error: string | null
  total_space: number | null
  used_space: number | null
  free_space: number | null
}

export type BackupType = 'files' | 'mysql' | 'postgres'
export type BackupStatus = 'pending' | 'running' | 'success' | 'failed'

export interface Backup {
  id: number
  backup_job_id: number
  backup_job: { id: number; name: string; type: BackupType; server_id: number } | null
  status: BackupStatus
  trigger: 'manual' | 'schedule'
  has_file: boolean
  file_name: string | null
  size_bytes: number | null
  sha256: string | null
  md5: string | null
  started_at: string | null
  finished_at: string | null
  duration_seconds: number | null
  error: string | null
  log?: string | null
  created_at: string
  updated_at: string
}

export interface BackupJob {
  id: number
  name: string
  server_id: number
  server: { id: number; name: string } | null
  type: BackupType
  paths: string[] | null
  excludes: string[] | null
  db_mode: 'ssh' | 'direct' | null
  db_host: string | null
  db_port: number | null
  db_name: string | null
  db_user: string | null
  has_db_password: boolean
  db_ssl_verify: boolean
  compression_level: number
  schedule: string | null
  enabled: boolean
  next_run_at: string | null
  last_scheduled_at: string | null
  retention_count: number | null
  retention_days: number | null
  storage_target_ids: number[] | null
  backups_count: number
  latest_backup: Backup | null
  created_at: string
  updated_at: string
}

export type MonitorType = 'http' | 'keyword' | 'tcp' | 'ping' | 'ssl' | 'heartbeat'

export interface UptimeWindow {
  percent: number | null
  checks: number
  failed: number
}
export type Uptime = Record<'24h' | '7d' | '30d', UptimeWindow>

export interface Incident {
  id: number
  monitor_id: number
  monitor?: { id: number; name: string; type: MonitorType }
  status: 'open' | 'resolved'
  started_at: string
  resolved_at: string | null
  reason: string | null
  duration_seconds: number
}

export interface Monitor {
  id: number
  name: string
  type: MonitorType
  target: string | null
  interval_seconds: number
  timeout_seconds: number
  expected_status: number | null
  keyword: string | null
  ssl_expiry_days: number | null
  heartbeat_token: string | null
  heartbeat_url: string | null
  heartbeat_grace_seconds: number | null
  last_heartbeat_at: string | null
  last_response_time_ms: number | null
  last_error: string | null
  failure_threshold: number
  enabled: boolean
  status: 'up' | 'down' | null
  consecutive_failures: number
  last_checked_at: string | null
  next_check_at: string | null
  open_incident: Incident | null
  uptime: Uptime
  created_at: string
  updated_at: string
}

export interface MonitorCheck {
  id: number
  monitor_id: number
  status: 'up' | 'down'
  response_time_ms: number | null
  status_code: number | null
  error: string | null
  checked_at: string
}
