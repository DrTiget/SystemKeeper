import type { ProjectRef } from './projects'

/**
 * Мониторинг ресурсов серверов — метрики снимаются по SSH с уже настроенного подключения.
 *
 * GET /metrics/servers                          — текущие показатели всех серверов (ServerMetrics[])
 * GET /servers/{id}/metrics                     — { data: ServerMetrics } текущие показатели и настройки сбора
 * GET /servers/{id}/metrics/history?period=1h|24h|7d|30d — { data: MetricPoint[] }, старые → новые
 *     (на длинных периодах — усреднение по интервалам, ~300 точек)
 * PUT /servers/{id}/metrics/settings            — MetricsSettings → { data: ServerMetrics }
 */

/** ok — данные свежие; stale — давно не обновлялись; unreachable — сервер не ответил; disabled — сбор выключен */
export type MetricsStatus = 'ok' | 'stale' | 'unreachable' | 'disabled'

export interface MetricsSettings {
  enabled: boolean
  /** Интервал сбора, секунд (30…3600) */
  interval: number
  /** Сколько дней хранить историю */
  retention_days: number
  /** Точки монтирования, которые не показывать (tmpfs, snap…) */
  disks_exclude: string[]
}

export interface DiskUsage {
  mount: string
  /** Устройство, например /dev/sda1 */
  device: string | null
  used: number
  total: number
}

export interface ServerMetrics {
  server: { id: number; name: string; host: string; project: ProjectRef | null }
  status: MetricsStatus
  /** Когда сняты показатели; null — ещё не снимались */
  collected_at: string | null
  error: string | null
  settings: MetricsSettings
  /** Загрузка CPU, % (0…100, по всем ядрам) */
  cpu: number | null
  cpu_cores: number | null
  /** Средняя нагрузка за 1, 5 и 15 минут */
  load: [number, number, number] | null
  /** Байты */
  memory: { used: number; total: number } | null
  swap: { used: number; total: number } | null
  disks: DiskUsage[]
  /** Байт/с */
  network: { rx: number; tx: number } | null
  /** Время работы сервера, секунд */
  uptime: number | null
  os: string | null
  /** Последний час: CPU и память в %, для мини-графиков в списке */
  spark: { cpu: number[]; memory: number[] }
}

export interface MetricPoint {
  at: string
  /** % */
  cpu: number | null
  /** % */
  memory: number | null
  /** % */
  swap: number | null
  load1: number | null
  /** Байт/с */
  rx: number | null
  tx: number | null
  /** Заполненность дисков, % — по точке монтирования */
  disks: Record<string, number>
}

export const METRICS_STATUS: Record<MetricsStatus, { label: string; dot: string }> = {
  ok: { label: 'Данные свежие', dot: 'bg-emerald-500' },
  stale: { label: 'Данные устарели', dot: 'bg-amber-500' },
  unreachable: { label: 'Не отвечает', dot: 'bg-destructive' },
  disabled: { label: 'Сбор выключен', dot: 'bg-muted-foreground' },
}

export const pct = (used: number, total: number) => (total ? (used / total) * 100 : 0)

/** Цвет заполненности: до 80% — обычный, до 90% — внимание, выше — тревога */
export const usageTone = (p: number | null) => (p === null ? 'bg-muted' : p >= 90 ? 'bg-destructive' : p >= 80 ? 'bg-amber-500' : 'bg-primary')

export function formatRate(bps: number | null): string {
  if (bps === null) return '—'
  const units = ['Б/с', 'КБ/с', 'МБ/с', 'ГБ/с']
  let i = 0
  while (bps >= 1024 && i < units.length - 1) {
    bps /= 1024
    i++
  }
  return `${bps.toFixed(bps < 10 && i ? 1 : 0)} ${units[i]}`
}

export function formatUptimeSec(s: number | null): string {
  if (s === null) return '—'
  const d = Math.floor(s / 86400)
  const h = Math.floor((s % 86400) / 3600)
  return d ? `${d} д ${h} ч` : `${h} ч ${Math.floor((s % 3600) / 60)} мин`
}
