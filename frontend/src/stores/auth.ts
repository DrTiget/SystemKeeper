import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, ApiError, getToken, setToken } from '@/api/client'
import { applyTheme, type Theme } from '@/lib/theme'

export interface User {
  id: number
  name: string
  email: string
  settings: { theme: Theme }
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(getToken())
  const user = ref<User | null>(null)

  function setUser(value: User) {
    user.value = value
    applyTheme(value.settings.theme)
  }

  function clear() {
    token.value = null
    user.value = null
    setToken(null)
  }

  async function login(email: string, password: string) {
    const res = await api<{ token: string; user: User }>('/auth/login', {
      method: 'POST',
      body: { email, password, device_name: 'web' },
    })
    setToken(res.token)
    token.value = res.token
    setUser(res.user)
  }

  async function fetchUser() {
    try {
      setUser((await api<{ data: User }>('/profile')).data)
    } catch (e) {
      if (!(e instanceof ApiError) || e.status !== 401) throw e
    }
  }

  async function logout() {
    try {
      await api('/auth/logout', { method: 'POST' })
    } catch {
      // токен уже недействителен — всё равно выходим
    }
    clear()
  }

  return { token, user, setUser, login, fetchUser, logout, clear }
})
