import type { ProjectRef } from './projects'
import type { Retention } from './storages'
export type Engine = 'mysql' | 'postgres'
export type Via = 'ssh' | 'direct'

export interface MysqlOptions {
  lock_mode: 'single_transaction' | 'lock_tables' | 'none'
  routines: boolean
  events: boolean
  triggers: boolean
  compress: boolean
}

export interface PostgresOptions {
  format: 'custom' | 'plain'
  no_owner: boolean
  compress: boolean
}

export interface DatabaseConnection {
  id: number
  name: string
  project: ProjectRef | null
  description: string | null
  engine: Engine
  via: Via
  server: { id: number; name: string; host: string } | null
  docker_container: string | null
  host: string
  port: number
  username: string
  has_password: boolean
  maintenance_database: string | null
  backup: {
    enabled: boolean
    schedule: string
    timezone: string
    next_run_at: string | null
    databases: string[]
    exclude_tables: string[]
    schema_only_tables: string[]
    options: Partial<MysqlOptions & PostgresOptions>
  }
  retention: Retention
  last_check: { status: 'ok' | 'failed' | null; error: string | null; checked_at: string | null; server_version: string | null }
}

export interface DbSchema {
  name: string
  size: number | null
  system: boolean
}

export interface DbTable {
  name: string
  rows: number | null
  size: number | null
}

export const ENGINE_LABELS: Record<Engine, string> = { mysql: 'MySQL / MariaDB', postgres: 'PostgreSQL' }
