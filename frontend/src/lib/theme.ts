export type Theme = 'light' | 'dark' | 'system'

const THEME_KEY = 'systemkeeper_theme'
const media = matchMedia('(prefers-color-scheme: dark)')
let current: Theme = (localStorage.getItem(THEME_KEY) as Theme | null) ?? 'system'

function render() {
  document.documentElement.classList.toggle('dark', current === 'dark' || (current === 'system' && media.matches))
}

media.addEventListener('change', render)

/** Применяет тему из настроек профиля и запоминает её для следующей загрузки (index.html). */
export function applyTheme(theme: Theme) {
  current = theme
  localStorage.setItem(THEME_KEY, theme)
  render()
}
