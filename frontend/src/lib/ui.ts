export const inputClass =
  'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base md:text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:bg-input/30'

const btnBase =
  'inline-flex h-9 items-center justify-center gap-2 rounded-md px-4 text-sm font-medium whitespace-nowrap transition-all focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50'

export const btnClass = {
  primary: `${btnBase} bg-primary text-primary-foreground hover:bg-primary/90`,
  outline: `${btnBase} border border-input bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30`,
  destructive: `${btnBase} border border-input bg-background text-destructive shadow-xs hover:bg-destructive/10 dark:bg-input/30`,
}

export function formatDate(iso: string | null): string {
  return iso ? new Date(iso).toLocaleString('ru-RU', { dateStyle: 'medium', timeStyle: 'short' }) : '—'
}
