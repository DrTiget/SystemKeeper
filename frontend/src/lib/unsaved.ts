import { onBeforeUnmount, onMounted, type Ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'

/** Предупреждает о несохранённых изменениях при уходе со страницы и закрытии вкладки. */
export function useUnsavedGuard(dirty: Ref<boolean>) {
  const onUnload = (e: BeforeUnloadEvent) => {
    if (dirty.value) e.preventDefault()
  }
  onMounted(() => window.addEventListener('beforeunload', onUnload))
  onBeforeUnmount(() => window.removeEventListener('beforeunload', onUnload))
  onBeforeRouteLeave(() => !dirty.value || confirm('Изменения не сохранены. Уйти со страницы?'))
}

/**
 * Текст для общей строки ошибок формы: сообщения полей, которые форма не показывает рядом с полем.
 * shown — поля (и списки: `paths` покрывает `paths.0`…), ошибки которых видны у самих полей.
 */
export function unshownErrors(errors: Record<string, string[]>, shown: string[]): string {
  const rest = Object.entries(errors)
    .filter(([k]) => !shown.some((f) => k === f || k.startsWith(`${f}.`)))
    .flatMap(([, v]) => v)
  return rest.length ? rest.join('\n') : 'Проверьте отмеченные поля'
}
