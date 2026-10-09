import type { MetricsSettings, ServerMetric } from '@/api/types'

export function formatUptime(sec: number): string {
  const d = Math.floor(sec / 86400)
  const h = Math.floor((sec % 86400) / 3600)
  const m = Math.floor((sec % 3600) / 60)
  if (d) return `${d} д ${h} ч`
  if (h) return `${h} ч ${m} мин`
  return `${m} мин`
}

export const pct = (v: number | null | undefined) => (v == null ? '—' : `${v.toLocaleString('ru-RU', { maximumFractionDigits: 1 })}%`)

/** Значение достигло порога (null — проверка выключена). */
export const exceeded = (value: number, threshold: number | null | undefined) => threshold != null && value >= threshold

export function metricFlags(m: ServerMetric, s: MetricsSettings) {
  const t = s.thresholds
  return {
    load: exceeded(m.load.per_core, t.load_per_core),
    ram: exceeded(m.memory.used_percent, t.ram_percent),
    disk: (used: number) => exceeded(used, t.disk_percent),
  }
}
