/**
 * Небольшой Markdown для описаний релизов: заголовки, списки, абзацы, **жирный**, `код`, ссылки.
 * Сначала экранирует HTML, поэтому результат безопасно вставлять через v-html.
 */
const escape = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

function inline(s: string): string {
  return escape(s)
    .replace(/`([^`]+)`/g, '<code class="rounded bg-muted px-1 font-mono text-[0.85em]">$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong class="font-semibold text-foreground">$1</strong>')
    .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener" class="text-primary hover:underline">$1</a>')
    .replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, '$1<a href="$2" target="_blank" rel="noopener" class="text-primary hover:underline">$2</a>')
}

export function renderMarkdown(md: string): string {
  const out: string[] = []
  let list = false
  const closeList = () => {
    if (list) out.push('</ul>')
    list = false
  }

  for (const raw of md.replace(/\r/g, '').split('\n')) {
    const line = raw.trimEnd()
    const heading = line.match(/^(#{1,6})\s+(.*)$/)
    const item = line.match(/^\s*[-*]\s+(.*)$/)
    if (heading) {
      closeList()
      out.push(`<h5 class="mt-3 text-xs font-semibold tracking-wide text-muted-foreground uppercase first:mt-0">${inline(heading[2])}</h5>`)
    } else if (item) {
      if (!list) out.push('<ul class="grid list-disc gap-1 pl-5 marker:text-muted-foreground">')
      list = true
      out.push(`<li>${inline(item[1])}</li>`)
    } else if (line.trim()) {
      closeList()
      out.push(`<p>${inline(line)}</p>`)
    } else {
      closeList()
    }
  }
  closeList()

  return out.join('')
}
