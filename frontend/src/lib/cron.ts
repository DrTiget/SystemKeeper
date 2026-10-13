/** Простые расписания, которые выбираются в интерфейсе; всё остальное — «свой cron». */
export type SchedulePreset =
  | { kind: 'hourly'; minute: number }
  | { kind: 'daily'; hour: number; minute: number }
  | { kind: 'weekly'; weekday: number; hour: number; minute: number }
  | { kind: 'custom'; cron: string }

export const WEEKDAYS = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота']

const num = (s: string, max: number): number | null => (/^\d{1,2}$/.test(s) && Number(s) <= max ? Number(s) : null)

export function parseCron(cron: string): SchedulePreset {
  const parts = cron.trim().split(/\s+/)
  if (parts.length === 5) {
    const [mi, h, dom, mon, dow] = parts as [string, string, string, string, string]
    const minute = num(mi, 59)
    const hour = num(h, 23)
    if (minute !== null && dom === '*' && mon === '*') {
      if (h === '*' && dow === '*') return { kind: 'hourly', minute }
      if (hour !== null && dow === '*') return { kind: 'daily', hour, minute }
      const weekday = num(dow, 7)
      if (hour !== null && weekday !== null) return { kind: 'weekly', weekday: weekday % 7, hour, minute }
    }
  }
  return { kind: 'custom', cron: cron.trim() }
}

export function buildCron(p: SchedulePreset): string {
  switch (p.kind) {
    case 'hourly':
      return `${p.minute} * * * *`
    case 'daily':
      return `${p.minute} ${p.hour} * * *`
    case 'weekly':
      return `${p.minute} ${p.hour} * * ${p.weekday}`
    case 'custom':
      return p.cron.trim()
  }
}

const hhmm = (h: number, m: number) => `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`

/** Короткое описание расписания для таблицы. */
export function describeCron(cron: string): string {
  const p = parseCron(cron)
  switch (p.kind) {
    case 'hourly':
      return `Каждый час в :${String(p.minute).padStart(2, '0')}`
    case 'daily':
      return `Каждый день в ${hhmm(p.hour, p.minute)}`
    case 'weekly':
      return `${WEEKDAYS[p.weekday]}, ${hhmm(p.hour, p.minute)}`
    case 'custom':
      return `cron: ${p.cron}`
  }
}
