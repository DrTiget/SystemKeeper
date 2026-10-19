/**
 * Приложения — интеграции со статичным API-токеном (только администратор).
 *
 *   GET    /applications              — { data: Application[] }
 *   GET    /applications/scopes       — { data: { sections, scopes } }
 *   POST   /applications              — ApplicationInput → { data: Application & { token } } (токен — один раз)
 *   PATCH  /applications/{id}         — Partial<ApplicationInput> → { data: Application }
 *   DELETE /applications/{id}
 *   POST   /applications/{id}/token   — перевыпуск → { data: Application & { token } }
 *   DELETE /applications/{id}/token   — отзыв → { data: Application }
 *
 * Права: `<раздел>:read` / `<раздел>:write` (write включает read), `*:read` / `*:write` — все разделы.
 */
import { api } from './client'

export interface Application {
  id: number
  name: string
  description: string | null
  is_active: boolean
  scopes: string[]
  has_token: boolean
  token_prefix: string | null
  token_created_at: string | null
  /** Только в ответе на создание и перевыпуск */
  token?: string
  last_used_at: string | null
  last_used_ip: string | null
  created_by: { id: number; name: string } | null
  created_at: string
}

export interface ApplicationInput {
  name: string
  description: string | null
  is_active: boolean
  scopes: string[]
}

export interface ScopeSection {
  key: string
  label: string
}

export const listApplications = () => api<{ data: Application[] }>('/applications').then((r) => r.data)
export const applicationSections = () => api<{ data: { sections: ScopeSection[] } }>('/applications/scopes').then((r) => r.data.sections)
export const createApplication = (body: ApplicationInput) => api<{ data: Application }>('/applications', { method: 'POST', body }).then((r) => r.data)
export const updateApplication = (id: number, body: Partial<ApplicationInput>) =>
  api<{ data: Application }>(`/applications/${id}`, { method: 'PATCH', body }).then((r) => r.data)
export const deleteApplication = (id: number) => api(`/applications/${id}`, { method: 'DELETE' })
export const regenerateToken = (id: number) => api<{ data: Application }>(`/applications/${id}/token`, { method: 'POST' }).then((r) => r.data)
export const revokeToken = (id: number) => api<{ data: Application }>(`/applications/${id}/token`, { method: 'DELETE' }).then((r) => r.data)
