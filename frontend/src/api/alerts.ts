import type { ProjectRef } from './projects'

/**
 * Алерты: каналы уведомлений, правила и сами срабатывания.
 *
 * Каналы
 *   GET    /alert-channels                  — AlertChannel[]
 *   POST   /alert-channels                  — AlertChannelInput
 *   PATCH  /alert-channels/{id}             — частичный AlertChannelInput (секреты: пусто — оставить прежние)
 *   DELETE /alert-channels/{id}
 *   POST   /alert-channels/{id}/test        — отправить тестовое сообщение → { ok: boolean, error: string | null }
 * Правила
 *   GET    /alert-rules?project_id=         — AlertRule[]
 *   POST   /alert-rules                     — AlertRuleInput
 *   GET    /alert-rules/{id}, PATCH, DELETE — PATCH {enabled} — включить/выключить
 *   GET    /alert-rules/suggestions         — AlertSuggestion[]: рекомендуемые правила, которых ещё нет
 *   POST   /alert-rules/suggestions/apply   — { keys, channel_ids } → AlertRule[] (201)
 * Срабатывания
 *   GET    /alerts?status=firing|resolved&project_id=&severity=&per_page=&page= — Alert[] (+ meta пагинации)
 *   GET    /alerts/summary                  — { firing: number, critical: number } — для меню
 *   POST   /alerts/{id}/ack                 — взять в работу: повторные уведомления по нему не шлются
 */

export type ChannelType = 'telegram' | 'email' | 'webhook'

export interface AlertChannel {
  id: number
  name: string
  type: ChannelType
  enabled: boolean
  /** Без секретов: токен бота не возвращается, только has_token */
  config: {
    chat_id?: string
    has_token?: boolean
    recipients?: string[]
    url?: string
    has_secret?: boolean
  }
  /** Сколько правил шлют в этот канал */
  rules_count: number
  last_sent_at: string | null
  last_error: string | null
  created_at: string
}

export interface AlertChannelInput {
  name: string
  type: ChannelType
  enabled: boolean
  config: {
    /** telegram */
    bot_token?: string
    chat_id?: string
    /** email */
    recipients?: string[]
    /** webhook: POST JSON на адрес; secret — подпись X-Signature (HMAC-SHA256 тела) */
    url?: string
    secret?: string
  }
}

/** Что отслеживает правило */
export type AlertKind =
  | 'server_cpu'
  | 'server_memory'
  | 'server_disk'
  | 'server_load'
  | 'server_unreachable'
  | 'monitor_down'
  | 'monitor_ssl'
  | 'backup_failed'
  | 'backup_missing'
  | 'storage_full'

export type Severity = 'warning' | 'critical'
export type ObjectType = 'server' | 'monitor' | 'database' | 'storage'

export interface AlertRuleInput {
  name: string
  enabled: boolean
  kind: AlertKind
  severity: Severity
  /** Порог: % для CPU/памяти/диска/хранилища, нагрузка на ядро для load, дни для SSL, часы для backup_missing */
  threshold: number | null
  /** Сколько минут условие должно держаться, прежде чем сработать (0 — сразу) */
  duration: number
  /** Область: проект (null — все проекты) и конкретные объекты (пусто — все объекты нужного типа в области) */
  project_id: number | null
  targets: { type: ObjectType; id: number }[]
  /** Куда слать */
  channel_ids: number[]
  /** Напоминать, пока не решено, каждые N минут; null — один раз */
  repeat_every: number | null
  /** Сообщить, когда проблема ушла */
  notify_resolved: boolean
}

export interface AlertRule extends AlertRuleInput {
  id: number
  project: ProjectRef | null
  /** Объекты из targets — с названиями */
  objects: { type: ObjectType; id: number; name: string }[]
  channels: { id: number; name: string; type: ChannelType }[]
  /** Сейчас срабатываний по правилу */
  firing_count: number
  last_fired_at: string | null
  created_at: string
}

/** Рекомендуемое правило: поля правила на все проекты и объекты + зачем оно и сколько объектов под него попадёт */
export interface AlertSuggestion extends Omit<AlertRuleInput, 'enabled' | 'channel_ids'> {
  /** Идентификатор для apply: server_disk_warning, server_disk_critical, monitor_down… */
  key: string
  /** «3 сервера с метриками» */
  reason: string
  objects_count: number
}

export interface Alert {
  id: number
  rule: { id: number; name: string; kind: AlertKind }
  severity: Severity
  status: 'firing' | 'resolved'
  object: { type: ObjectType; id: number; name: string; project: ProjectRef | null }
  /** Человекочитаемо: «CPU 97% дольше 5 мин», «Диск / заполнен на 91%» */
  message: string
  started_at: string
  resolved_at: string | null
  acknowledged_at: string | null
  acknowledged_by: { id: number; name: string } | null
  /** Отправленные уведомления */
  notifications: { channel: { id: number; name: string; type: ChannelType }; at: string; ok: boolean; error: string | null }[]
}

export const CHANNEL_TYPES: Record<ChannelType, { label: string; hint: string; icon: string }> = {
  telegram: { label: 'Telegram', hint: 'Сообщение от вашего бота в чат или группу', icon: 'send' },
  email: { label: 'Почта', hint: 'Письмо на один или несколько адресов', icon: 'mail' },
  webhook: { label: 'Вебхук', hint: 'POST с JSON на ваш адрес — для Slack, n8n, своих систем', icon: 'webhook' },
}

/**
 * Типы правил: к каким объектам относится, есть ли порог и как его подписать, значения по умолчанию.
 * thresholdLabel — фраза перед полем порога, unit — после.
 */
export const ALERT_KINDS: Record<
  AlertKind,
  {
    group: 'Серверы' | 'Аптайм' | 'Бекапы'
    label: string
    hint: string
    /** К каким объектам применимо */
    objects: ObjectType[]
    thresholdLabel?: string
    unit?: string
    min?: number
    max?: number
    step?: number
    /** Есть ли смысл в «держится N минут» */
    duration: boolean
    defaults: { threshold: number | null; duration: number; severity: Severity }
  }
> = {
  server_cpu: { group: 'Серверы', label: 'Высокая загрузка CPU', hint: 'Процессор загружен выше порога', objects: ['server'], thresholdLabel: 'CPU выше', unit: '%', min: 1, max: 100, duration: true, defaults: { threshold: 90, duration: 5, severity: 'warning' } },
  server_memory: { group: 'Серверы', label: 'Мало памяти', hint: 'Оперативная память занята выше порога', objects: ['server'], thresholdLabel: 'Память занята больше', unit: '%', min: 1, max: 100, duration: true, defaults: { threshold: 90, duration: 5, severity: 'warning' } },
  server_disk: { group: 'Серверы', label: 'Заканчивается место на диске', hint: 'Любой из дисков заполнен выше порога', objects: ['server'], thresholdLabel: 'Диск заполнен больше', unit: '%', min: 1, max: 100, duration: false, defaults: { threshold: 85, duration: 0, severity: 'warning' } },
  server_load: { group: 'Серверы', label: 'Высокая нагрузка (load average)', hint: 'Средняя нагрузка за 5 минут на одно ядро выше порога', objects: ['server'], thresholdLabel: 'Load на ядро больше', unit: '', min: 0.1, max: 100, step: 0.1, duration: true, defaults: { threshold: 2, duration: 10, severity: 'warning' } },
  server_unreachable: { group: 'Серверы', label: 'Сервер не отвечает', hint: 'Метрики не удаётся снять по SSH', objects: ['server'], duration: true, defaults: { threshold: null, duration: 3, severity: 'critical' } },
  monitor_down: { group: 'Аптайм', label: 'Монитор недоступен', hint: 'Сайт, API или порт не отвечает', objects: ['monitor'], duration: true, defaults: { threshold: null, duration: 0, severity: 'critical' } },
  monitor_ssl: { group: 'Аптайм', label: 'Истекает сертификат', hint: 'HTTPS-сертификат истекает скоро', objects: ['monitor'], thresholdLabel: 'Осталось меньше', unit: 'дн.', min: 1, max: 90, duration: false, defaults: { threshold: 14, duration: 0, severity: 'warning' } },
  backup_failed: { group: 'Бекапы', label: 'Бекап не удался', hint: 'Бекап сервера или базы завершился ошибкой', objects: ['server', 'database'], duration: false, defaults: { threshold: null, duration: 0, severity: 'critical' } },
  backup_missing: { group: 'Бекапы', label: 'Давно не было бекапа', hint: 'Нет успешного бекапа дольше порога', objects: ['server', 'database'], thresholdLabel: 'Нет успешного бекапа дольше', unit: 'ч', min: 1, max: 720, duration: false, defaults: { threshold: 26, duration: 0, severity: 'warning' } },
  storage_full: { group: 'Бекапы', label: 'Хранилище почти заполнено', hint: 'На диске или в облаке для бекапов мало места', objects: ['storage'], thresholdLabel: 'Заполнено больше', unit: '%', min: 1, max: 100, duration: false, defaults: { threshold: 90, duration: 0, severity: 'warning' } },
}

export const SEVERITY: Record<Severity, { label: string; badge: string; dot: string }> = {
  warning: { label: 'Предупреждение', badge: 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-400', dot: 'bg-amber-500' },
  critical: { label: 'Критично', badge: 'border-destructive/40 bg-destructive/10 text-destructive', dot: 'bg-destructive' },
}

export const OBJECT_LABELS: Record<ObjectType, { one: string; many: string; icon: string }> = {
  server: { one: 'Сервер', many: 'серверы', icon: 'server' },
  monitor: { one: 'Монитор', many: 'мониторы', icon: 'activity' },
  database: { one: 'База данных', many: 'базы данных', icon: 'database' },
  storage: { one: 'Хранилище', many: 'хранилища', icon: 'hard-drive' },
}

/** Условие правила одной строкой: «CPU выше 90% дольше 5 мин» */
export function describeCondition(r: Pick<AlertRuleInput, 'kind' | 'threshold' | 'duration'>): string {
  const k = ALERT_KINDS[r.kind]
  const base = k.thresholdLabel && r.threshold !== null ? `${k.thresholdLabel} ${r.threshold}${k.unit === '%' ? '%' : k.unit ? ` ${k.unit}` : ''}` : k.label
  return k.duration && r.duration ? `${base} дольше ${r.duration} мин` : base
}

/** Ссылка на объект алерта */
export function objectRoute(o: { type: ObjectType; id: number }) {
  return o.type === 'storage' ? { name: 'storages' } : { name: o.type, params: { id: o.id } }
}

/** Действует ли правило на объект: объект указан явно или правило на все объекты в проекте (или во всех проектах). */
export function ruleApplies(r: AlertRule, type: ObjectType, id: number, projectId: number | null): boolean {
  if (!r.enabled || !ALERT_KINDS[r.kind].objects.includes(type)) return false
  if (r.targets.length) return r.targets.some((o) => o.type === type && o.id === id)
  return r.project_id === null || r.project_id === projectId
}
