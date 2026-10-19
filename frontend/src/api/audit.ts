/**
 * Журнал действий (администратор или приложение с audit:read).
 *
 *   GET /audit-logs?actor_type&actor_id&event&subject_type&subject_id&from&to&search&page&per_page — пагинация
 *   GET /settings/audit   — { data: { retention_days } }
 *   PUT /settings/audit   — { retention_days } (1–3650)
 */
import { api } from './client'

export type ActorType = 'user' | 'application' | 'system'

export interface AuditLogEntry {
  id: number
  actor_type: ActorType
  actor_id: number | null
  actor_name: string | null
  event: string
  subject_type: string | null
  subject_id: number | null
  subject_label: string | null
  /** Изменения ({ old, new } / { attributes }) или подробности; секреты — `***` */
  changes: Record<string, unknown> | null
  ip: string | null
  user_agent: string | null
  created_at: string
}

export interface AuditPage {
  data: AuditLogEntry[]
  meta: { current_page: number; last_page: number; total: number }
}

export const listAuditLogs = (params: Record<string, string>) => api<AuditPage>(`/audit-logs?${new URLSearchParams(params)}`)
export const auditSettings = () => api<{ data: { retention_days: number } }>('/settings/audit').then((r) => r.data)
export const saveAuditSettings = (retention_days: number) =>
  api<{ data: { retention_days: number } }>('/settings/audit', { method: 'PUT', body: { retention_days } }).then((r) => r.data)
