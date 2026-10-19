/**
 * Настройки уведомлений (только администратор).
 *
 *   GET  /settings/notifications               — NotificationSettings
 *   PUT  /settings/notifications               — NotificationSettingsInput → NotificationSettings
 *   POST /settings/notifications/test-mail     — { to } → { ok, error }; 422, если почта не настроена
 *   POST /settings/notifications/test-telegram — доступность адреса Bot API через прокси → { ok, error }
 *
 * Пароли API не отдаёт (только has_password). Пустой или отсутствующий пароль при сохранении оставляет прежний,
 * clear_password: true — стирает.
 */

export type MailEncryption = 'none' | 'tls' | 'ssl'
export type ProxyType = 'http' | 'socks5'

export interface NotificationSettings {
  mail: {
    /** Есть чем отправлять: SMTP из настроек или MAIL_* из .env */
    configured: boolean
    host: string | null
    port: number
    encryption: MailEncryption
    username: string | null
    has_password: boolean
    from_address: string | null
    from_name: string | null
    /** settings — SMTP из этих настроек, env — из .env сервера, none — не настроена */
    source: 'settings' | 'env' | 'none'
  }
  telegram: {
    api_url: string
    proxy: {
      enabled: boolean
      type: ProxyType
      host: string | null
      port: number | null
      username: string | null
      has_password: boolean
    }
  }
}

export interface TestResult {
  ok: boolean
  error: string | null
}

export const DEFAULT_TELEGRAM_API = 'https://api.telegram.org'
