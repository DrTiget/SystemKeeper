import type { ProjectRef } from './projects'

/**
 * Мониторинг доступности (uptime) — контракт API /api/v1/monitors.
 *
 * GET    /monitors?project_id=&status=&per_page=  — список, у каждого монитора `recent` (последние проверки для полосы)
 * POST   /monitors                                 — создать (тело — MonitorInput)
 * GET    /monitors/{id}                            — монитор
 * PATCH  /monitors/{id}                            — изменить (частичный MonitorInput; пауза — {enabled: false})
 * DELETE /monitors/{id}
 * POST   /monitors/test                            — разовая проверка по MonitorInput без сохранения → MonitorCheckResult
 * POST   /monitors/{id}/check                      — проверить сейчас → MonitorCheckResult (и записать как проверку)
 * GET    /monitors/{id}/heartbeats?period=24h|7d|30d — проверки за период (для графика; на 7d/30d можно усреднять по интервалам)
 * GET    /monitors/{id}/incidents?per_page=        — сбои: периоды, когда монитор был недоступен
 */

export type MonitorType = 'http' | 'tcp' | 'ping' | 'dns'
export type MonitorStatus = 'up' | 'down' | 'pending' | 'paused'
export type HeartbeatStatus = 'up' | 'down' | 'pending'
export type HttpMethod = 'GET' | 'HEAD' | 'POST' | 'PUT' | 'PATCH' | 'DELETE' | 'OPTIONS'
export type DnsRecordType = 'A' | 'AAAA' | 'CNAME' | 'MX' | 'TXT' | 'NS'

/** Настройки монитора — то, что редактируется в форме. */
export interface MonitorInput {
  name: string
  project_id: number | null
  type: MonitorType
  enabled: boolean

  /** http: адрес страницы */
  url: string | null
  /** tcp, ping, dns: хост или IP */
  hostname: string | null
  /** tcp: порт */
  port: number | null

  /** http */
  method: HttpMethod
  /** Ожидаемые коды ответа: «200-299», «301», «404»… */
  accepted_statuses: string[]
  /** Слово на странице: есть (или нет при keyword_invert) — монитор работает */
  keyword: string | null
  keyword_invert: boolean
  /** Заголовки запроса */
  headers: { name: string; value: string }[]
  /** Тело запроса для POST/PUT/PATCH */
  body: string | null
  follow_redirects: boolean
  /** Не проверять сертификат (самоподписанный) */
  ignore_tls: boolean
  /** Предупреждать, когда сертификат истекает через столько дней; null — не следить */
  ssl_expiry_days: number | null

  /** dns */
  dns_record_type: DnsRecordType
  /** DNS-сервер; null — системный */
  dns_resolver: string | null
  /** Ожидаемое значение записи; null — достаточно, что запись есть */
  dns_expected: string | null

  /** Интервал проверок, секунд (20…86400) */
  interval: number
  /** Тайм-аут проверки, секунд */
  timeout: number
  /** Сколько неудачных проверок подряд до статуса «недоступен» (0 — сразу) */
  retries: number
  /** Интервал повторных проверок после неудачи, секунд */
  retry_interval: number
}

export interface Heartbeat {
  at: string
  status: HeartbeatStatus
  /** Время ответа, мс; null — ответа не было */
  response_ms: number | null
  /** Код HTTP-ответа */
  status_code?: number | null
  /** Пояснение: «200 OK», текст ошибки, «нет слова на странице»… */
  message: string | null
}

export interface Monitor extends MonitorInput {
  id: number
  project: ProjectRef | null
  /** paused — если enabled = false; pending — ещё не проверялся или идут повторные проверки */
  status: MonitorStatus
  /** С какого момента текущий статус */
  status_since: string | null
  last_check_at: string | null
  last_response_ms: number | null
  last_error: string | null
  /** Доля успешных проверок, % (0…100); null — нет данных за период */
  uptime: { h24: number | null; d7: number | null; d30: number | null }
  /** Среднее время ответа за 24 часа, мс */
  avg_response_ms: number | null
  /** http(s): сертификат */
  ssl: { valid_to: string; issuer: string | null; days_left: number } | null
  /** Последние проверки, старые → новые (до 50) — в списке и в карточке */
  recent: Heartbeat[]
  created_at: string
}

export interface MonitorIncident {
  id: number
  started_at: string
  /** null — сбой продолжается */
  ended_at: string | null
  /** Первая ошибка сбоя */
  error: string | null
}

export interface MonitorCheckResult {
  status: HeartbeatStatus
  response_ms: number | null
  status_code: number | null
  message: string | null
  /** Обновлённый монитор — только для POST /monitors/{id}/check */
  monitor?: Monitor
}

export const MONITOR_TYPES: { value: MonitorType; label: string; hint: string }[] = [
  { value: 'http', label: 'HTTP(S)', hint: 'Сайт или API: код ответа, слово на странице, сертификат' },
  { value: 'tcp', label: 'TCP-порт', hint: 'Порт отвечает: база, почта, SSH…' },
  { value: 'ping', label: 'Ping', hint: 'Хост отвечает на ICMP' },
  { value: 'dns', label: 'DNS', hint: 'Запись резолвится и совпадает с ожидаемой' },
]

export const MONITOR_STATUS: Record<MonitorStatus, { label: string; dot: string; text: string; bar: string }> = {
  up: { label: 'Работает', dot: 'bg-emerald-500', text: 'text-emerald-600 dark:text-emerald-400', bar: 'bg-emerald-500' },
  down: { label: 'Недоступен', dot: 'bg-destructive', text: 'text-destructive', bar: 'bg-destructive' },
  pending: { label: 'Проверяется', dot: 'bg-amber-500', text: 'text-amber-600 dark:text-amber-400', bar: 'bg-amber-500' },
  paused: { label: 'На паузе', dot: 'bg-muted-foreground', text: 'text-muted-foreground', bar: 'bg-muted-foreground' },
}

/** Что проверяется — одной строкой для списков. */
export function monitorTarget(m: Pick<Monitor, 'type' | 'url' | 'hostname' | 'port' | 'dns_record_type'>): string {
  switch (m.type) {
    case 'http':
      return m.url ?? ''
    case 'tcp':
      return `${m.hostname ?? ''}:${m.port ?? ''}`
    case 'dns':
      return `${m.dns_record_type} ${m.hostname ?? ''}`
    default:
      return m.hostname ?? ''
  }
}

export const formatUptime = (v: number | null) => (v === null ? '—' : `${v >= 99.995 ? 100 : Math.floor(v * 100) / 100}%`)

/** Интервал «30 с», «5 мин», «1 ч». */
export function formatInterval(sec: number): string {
  if (sec < 60) return `${sec} с`
  if (sec < 3600) return `${Math.round(sec / 60)} мин`
  return `${Math.round(sec / 3600)} ч`
}

/** Длительность периода: «3 мин», «2 ч 15 мин», «4 д 2 ч». */
export function formatSpan(fromIso: string, toIso?: string | null): string {
  const s = Math.max(0, Math.round(((toIso ? new Date(toIso) : new Date()).getTime() - new Date(fromIso).getTime()) / 1000))
  if (s < 60) return `${s} с`
  const m = Math.floor(s / 60)
  if (m < 60) return `${m} мин`
  const h = Math.floor(m / 60)
  if (h < 24) return `${h} ч ${m % 60} мин`
  return `${Math.floor(h / 24)} д ${h % 24} ч`
}

export const defaultMonitorInput = (projectId: number | null = null): MonitorInput => ({
  name: '',
  project_id: projectId,
  type: 'http',
  enabled: true,
  url: 'https://',
  hostname: null,
  port: null,
  method: 'GET',
  accepted_statuses: ['200-299'],
  keyword: null,
  keyword_invert: false,
  headers: [],
  body: null,
  follow_redirects: true,
  ignore_tls: false,
  ssl_expiry_days: 14,
  dns_record_type: 'A',
  dns_resolver: null,
  dns_expected: null,
  interval: 60,
  timeout: 30,
  retries: 1,
  retry_interval: 60,
})
