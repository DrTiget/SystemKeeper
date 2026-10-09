import type { MonitorType } from '@/api/types'

export const TYPES: Record<MonitorType, string> = {
  http: 'HTTP',
  keyword: 'Ключевое слово',
  tcp: 'TCP-порт',
  ping: 'Ping',
  ssl: 'SSL-сертификат',
  heartbeat: 'Heartbeat',
}

export function formatPercent(p: number | null | undefined): string {
  return p == null ? '—' : `${p.toLocaleString('ru-RU', { maximumFractionDigits: 2 })}%`
}
