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
  type: 'local' | 'yandex_disk'
  base_path: string
  enabled: boolean
  has_token: boolean
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
