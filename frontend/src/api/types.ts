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
  metrics_settings: MetricsSettings
  metrics_collected_at: string | null
  metrics_last_error: string | null
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

export interface Dashboard {
  servers: { total: number; check_ok: number; check_failed: number; unchecked: number }
  backups: {
    success_24h: number
    failed_24h: number
    success_7d: number
    failed_7d: number
    last_failed: { id: number; backup_job_id: number; job_name: string | null; error: string | null; finished_at: string }[]
    upcoming: { backup_job_id: number; job_name: string; next_run_at: string }[]
    local_size_bytes: number
    size_by_storage: { storage_target_id: number; name: string; type: StorageTarget['type']; copies: number; size_bytes: number }[]
    stale_days: number
    stale_jobs: { backup_job_id: number; job_name: string; last_success_at: string | null }[]
  }
  monitoring: {
    total: number
    up: number
    down: number
    paused: number
    pending: number
    active_incidents_count: number
    active_incidents: { id: number; monitor_id: number; monitor_name: string | null; reason: string | null; started_at: string }[]
    uptime_24h: number | null
    uptime_30d: number | null
  }
  storages: {
    id: number
    name: string
    type: StorageTarget['type']
    enabled: boolean
    last_checked_at: string | null
    last_check_status: 'ok' | 'failed' | null
    total_space: number | null
    free_space: number | null
  }[]
  generated_at: string
}

export interface MetricsSettings {
  enabled: boolean
  interval_minutes: number
  confirmations: number | null
  thresholds: { disk_percent: number | null; load_per_core: number | null; ram_percent: number | null }
}

export interface ServerMetric {
  id: number
  server_id: number
  collected_at: string
  load: { load1: number; load5: number; load15: number; per_core: number }
  cpu_count: number
  memory: { total_bytes: number; used_bytes: number; available_bytes: number; used_percent: number }
  swap: { total_bytes: number; used_bytes: number; used_percent: number | null }
  uptime_seconds: number
  disks: {
    mount: string
    device: string
    fstype: string | null
    total_bytes: number
    used_bytes: number
    avail_bytes: number
    used_percent: number
    inodes_total: number | null
    inodes_used: number | null
    inodes_free: number | null
  }[]
}

export interface MetricAlert {
  metric: 'disk' | 'load' | 'ram'
  target: string | null
  value: number
  threshold: number
  exceeded_since: string
}

export interface MetricsLatest {
  metric: ServerMetric | null
  alerts: MetricAlert[]
  collected_at: string | null
  last_error: string | null
}

interface Agg {
  avg: number
  max: number
}
export interface MetricPoint {
  time: string
  samples: number
  load1: Agg
  load5: Agg
  load15: Agg
  load_per_core: Agg
  mem_used_percent: Agg
  swap_used_percent: Agg | null
  mem_used_bytes: number
  disks: { mount: string; total_bytes: number; used_bytes: number; used_percent: Agg }[]
}

export interface MetricsHistory {
  from: string
  to: string
  bucket_seconds: number
  points: MetricPoint[]
}
