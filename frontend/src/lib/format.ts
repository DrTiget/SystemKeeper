import { ApiError } from '@/api/client'

export const inputClass =
  'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base md:text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:bg-input/30'
export const textareaClass = inputClass.replace('h-9', 'min-h-24').replace('py-1', 'py-2')

const btn =
  'inline-flex items-center justify-center gap-2 rounded-md text-sm font-medium whitespace-nowrap transition-all focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4'
export const btnClass = {
  default: `${btn} h-9 px-4 bg-primary text-primary-foreground hover:bg-primary/90`,
  outline: `${btn} h-9 px-4 border border-input bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30`,
  outlineSm: `${btn} h-8 px-3 border border-input bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30`,
  ghostSm: `${btn} h-8 px-3 hover:bg-accent hover:text-accent-foreground`,
  destructive: `${btn} h-9 px-4 bg-destructive text-white hover:bg-destructive/90 dark:bg-destructive/60`,
}

export function formatDate(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' })
}

/** Сообщение об ошибке запроса по-русски: 409/422/сеть. */
export function errorMessage(e: unknown, fallback = 'Не удалось выполнить действие'): string {
  if (!(e instanceof ApiError)) return 'Нет связи с сервером. Проверьте соединение и повторите'
  if (e.status === 409) return e.message && /[а-яё]/i.test(e.message) ? e.message : 'Действие невозможно: ресурс используется'
  if (e.status === 422) return Object.values(e.errors)[0]?.[0] ?? 'Проверьте введённые данные'
  if (e.status === 404) return 'Запись не найдена — возможно, она уже удалена'
  if (e.status === 429) return 'Слишком много запросов. Подождите и повторите'
  return fallback
}
