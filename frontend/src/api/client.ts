const BASE = '/api/v1'
const TOKEN_KEY = 'systemkeeper_token'

export const getToken = (): string | null => localStorage.getItem(TOKEN_KEY)
export const setToken = (token: string | null): void => {
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else localStorage.removeItem(TOKEN_KEY)
}

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors: Record<string, string[]> = {},
  ) {
    super(message)
  }
}

let onUnauthorized: () => void = () => {}
export const setUnauthorizedHandler = (fn: () => void): void => {
  onUnauthorized = fn
}

export async function api<T = unknown>(
  path: string,
  options: { method?: string; body?: unknown } = {},
): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = getToken()
  if (token) headers.Authorization = `Bearer ${token}`
  if (options.body !== undefined) headers['Content-Type'] = 'application/json'

  const res = await fetch(BASE + path, {
    method: options.method ?? 'GET',
    headers,
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
  })

  if (res.status === 204) return undefined as T
  const data = await res.json().catch(() => ({}))

  if (!res.ok) {
    // 401 на логине — это не истёкшая сессия, а неверные данные/отсутствие токена
    if (res.status === 401 && token) onUnauthorized()
    throw new ApiError(res.status, data.message ?? 'Ошибка запроса', data.errors ?? {})
  }
  return data as T
}
