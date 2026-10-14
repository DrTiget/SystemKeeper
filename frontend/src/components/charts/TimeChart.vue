<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { formatDate } from '@/lib/ui'

/**
 * График по времени: одна или несколько линий (до трёх — цвета --series-1…3, проверены на различимость).
 * Одна серия — без легенды (название — в заголовке карточки); несколько — легенда с последними значениями.
 * thresholds — пунктирные линии порогов (например, порог алерта).
 */
export interface ChartSeries {
  key: string
  label: string
  values: (number | null)[]
}
const props = withDefaults(
  defineProps<{
    times: string[]
    series: ChartSeries[]
    from: string
    to: string
    /** Верх шкалы; по умолчанию — «круглое» число над максимумом */
    max?: number
    format?: (v: number) => string
    thresholds?: { value: number; label: string }[]
    height?: number
  }>(),
  { format: (v: number) => String(Math.round(v)), height: 180 },
)

const COLORS = ['text-series-1', 'text-series-2', 'text-series-3']
const color = (i: number) => (props.series.length === 1 ? 'text-primary' : COLORS[i] ?? COLORS[0])

const PAD = { top: 10, right: 12, bottom: 22, left: 64 }
const el = ref<HTMLElement>()
const width = ref(600)
let ro: ResizeObserver | undefined
onMounted(() => {
  ro = new ResizeObserver(([e]) => (width.value = Math.max(240, e!.contentRect.width)))
  ro.observe(el.value!)
})
onBeforeUnmount(() => ro?.disconnect())

const t0 = computed(() => new Date(props.from).getTime())
const t1 = computed(() => new Date(props.to).getTime())
const ts = computed(() => props.times.map((t) => new Date(t).getTime()))
const hasData = computed(() => props.series.some((s) => s.values.some((v) => v !== null)))

const yMax = computed(() => {
  if (props.max !== undefined) return props.max
  const max = Math.max(0, ...props.series.flatMap((s) => s.values.filter((v): v is number => v !== null)), ...(props.thresholds ?? []).map((t) => t.value))
  if (!max) return 1
  const step = 10 ** Math.floor(Math.log10(max))
  return Math.ceil((max * 1.1) / step) * step
})
const H = computed(() => props.height)
const x = (t: number) => PAD.left + ((t - t0.value) / Math.max(1, t1.value - t0.value)) * (width.value - PAD.left - PAD.right)
const y = (v: number) => PAD.top + (1 - Math.min(v, yMax.value) / yMax.value) * (H.value - PAD.top - PAD.bottom)
const baseline = computed(() => H.value - PAD.bottom)

/** Линии разрываются на пропусках (null) */
function paths(values: (number | null)[]) {
  const out: string[] = []
  let cur = ''
  values.forEach((v, i) => {
    if (v === null) {
      if (cur) out.push(cur)
      cur = ''
    } else cur += `${cur ? 'L' : 'M'}${x(ts.value[i]!).toFixed(1)},${y(v).toFixed(1)}`
  })
  if (cur) out.push(cur)
  return out
}
/** Заливка — только для одной серии, чтобы не перекрывать линии */
const area = computed(() => {
  if (props.series.length !== 1) return []
  return paths(props.series[0]!.values).map((d) => {
    const xs = [...d.matchAll(/[ML]([\d.]+),/g)].map((m) => m[1])
    return `${d}L${xs.at(-1)},${baseline.value}L${xs[0]},${baseline.value}Z`
  })
})

const yTicks = computed(() => [0, 0.5, 1].map((k) => yMax.value * k))
const xTicks = computed(() => {
  const span = t1.value - t0.value
  const n = Math.max(2, Math.min(6, Math.floor(width.value / 120)))
  const long = span > 2 * 86400_000
  return Array.from({ length: n }, (_, i) => {
    const t = t0.value + (span * (i + 0.5)) / n
    const d = new Date(t)
    return { x: x(t), label: long ? d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }) : d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) }
  })
})

const last = (s: ChartSeries) => [...s.values].reverse().find((v) => v !== null) ?? null

const hover = ref<number | null>(null)
function onMove(e: PointerEvent) {
  const px = e.clientX - (e.currentTarget as SVGElement).getBoundingClientRect().left
  let best = -1
  ts.value.forEach((t, i) => {
    if (best === -1 || Math.abs(x(t) - px) < Math.abs(x(ts.value[best]!) - px)) best = i
  })
  hover.value = best === -1 ? null : best
}
const hx = computed(() => (hover.value === null ? 0 : x(ts.value[hover.value]!)))
</script>

<template>
  <div ref="el" class="relative grid gap-2">
    <ul v-if="series.length > 1" class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
      <li v-for="(s, i) in series" :key="s.key" class="flex items-center gap-1.5">
        <span class="h-0.5 w-3 rounded-full bg-current" :class="color(i)" />
        <span class="text-muted-foreground">{{ s.label }}</span>
        <span class="font-medium tabular-nums">{{ last(s) !== null ? format(last(s)!) : '—' }}</span>
      </li>
    </ul>
    <p v-if="!hasData" class="flex items-center justify-center text-sm text-muted-foreground" :style="{ height: `${height}px` }">Данных за этот период нет</p>
    <svg v-else :width="width" :height="height" class="block touch-none select-none" @pointermove="onMove" @pointerleave="hover = null">
      <g class="text-muted-foreground">
        <template v-for="t in yTicks" :key="t">
          <line :x1="PAD.left" :x2="width - PAD.right" :y1="y(t)" :y2="y(t)" stroke="currentColor" stroke-opacity="0.15" :stroke-dasharray="t ? '3 3' : undefined" />
          <text :x="PAD.left - 8" :y="y(t)" text-anchor="end" dominant-baseline="middle" class="fill-current text-[11px]">{{ format(t) }}</text>
        </template>
        <text v-for="t in xTicks" :key="t.x" :x="t.x" :y="height - 5" text-anchor="middle" class="fill-current text-[11px]">{{ t.label }}</text>
      </g>
      <g v-for="t in thresholds" :key="t.label" class="text-destructive">
        <line :x1="PAD.left" :x2="width - PAD.right" :y1="y(t.value)" :y2="y(t.value)" stroke="currentColor" stroke-opacity="0.7" stroke-dasharray="6 4" />
        <text :x="width - PAD.right" :y="y(t.value) - 4" text-anchor="end" class="fill-current stroke-card text-[11px]" stroke-width="4" paint-order="stroke" stroke-linejoin="round">{{ t.label }}</text>
      </g>
      <path v-for="(d, i) in area" :key="'a' + i" :d="d" class="text-primary" fill="currentColor" fill-opacity="0.12" />
      <g v-for="(s, si) in series" :key="s.key" :class="color(si)">
        <path v-for="(d, i) in paths(s.values)" :key="i" :d="d" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
      </g>
      <g v-if="hover !== null">
        <line :x1="hx" :x2="hx" :y1="PAD.top" :y2="baseline" class="stroke-foreground" stroke-opacity="0.3" />
        <template v-for="(s, si) in series" :key="s.key">
          <circle v-if="s.values[hover] != null" :cx="hx" :cy="y(s.values[hover]!)" r="4" :class="color(si)" fill="currentColor" class="stroke-card" stroke-width="2" />
        </template>
      </g>
    </svg>
    <div
      v-if="hover !== null"
      class="pointer-events-none absolute top-6 z-10 w-max rounded-md border bg-popover px-2.5 py-1.5 text-xs text-popover-foreground shadow-md"
      :style="hx > width / 2 ? { right: `${width - hx + 10}px` } : { left: `${hx + 10}px` }"
    >
      <div class="mb-0.5 text-muted-foreground">{{ formatDate(times[hover]!) }}</div>
      <div v-for="(s, si) in series" :key="s.key" class="flex items-center gap-1.5">
        <span class="size-2 rounded-full bg-current" :class="color(si)" />
        <span v-if="series.length > 1" class="text-muted-foreground">{{ s.label }}</span>
        <span class="font-medium tabular-nums">{{ s.values[hover] != null ? format(s.values[hover]!) : '—' }}</span>
      </div>
    </div>
  </div>
</template>
