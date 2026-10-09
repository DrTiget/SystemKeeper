<script setup lang="ts">
import { computed } from 'vue'

export interface ChartSeries {
  name: string
  /** Точки; v = null — разрыв линии. */
  points: { t: number; v: number | null }[]
}

const props = withDefaults(
  defineProps<{
    series: ChartSeries[]
    from: number
    to: number
    unit?: string
    /** Фиксированный максимум оси Y; иначе по данным. */
    yMax?: number
    threshold?: number | null
    /** Разрыв линии, если между соседними точками больше стольких мс. */
    gapMs?: number
    decimals?: number
  }>(),
  { unit: '', decimals: 0 },
)

const W = 600
const H = 160
const PAD = { l: 44, r: 8, t: 8, b: 20 }
const COLORS = ['stroke-chart-1', 'stroke-chart-2', 'stroke-chart-3', 'stroke-chart-4', 'stroke-chart-5']
const FILLS = ['fill-chart-1', 'fill-chart-2', 'fill-chart-3', 'fill-chart-4', 'fill-chart-5']

const max = computed(() => {
  if (props.yMax != null) return props.yMax
  const m = Math.max(0, props.threshold ?? 0, ...props.series.flatMap((s) => s.points.map((p) => p.v ?? 0)))
  return m <= 0 ? 1 : m * 1.1
})
const px = (t: number) => PAD.l + ((t - props.from) / Math.max(1, props.to - props.from)) * (W - PAD.l - PAD.r)
const py = (v: number) => PAD.t + (H - PAD.t - PAD.b) * (1 - Math.min(v, max.value) / max.value)

const lines = computed(() =>
  props.series.map((s) => {
    const segs: string[] = []
    const dots: { x: number; y: number; t: number; v: number }[] = []
    let cur: string[] = []
    let prev: number | null = null
    for (const p of s.points) {
      if (p.v == null || (prev != null && props.gapMs && p.t - prev > props.gapMs)) {
        if (cur.length) segs.push(cur.join(' '))
        cur = []
      }
      prev = p.v == null ? null : p.t
      if (p.v == null) continue
      cur.push(`${px(p.t).toFixed(1)},${py(p.v).toFixed(1)}`)
      dots.push({ x: px(p.t), y: py(p.v), t: p.t, v: p.v })
    }
    if (cur.length) segs.push(cur.join(' '))
    return { name: s.name, segs, dots }
  }),
)
const yTicks = computed(() => [0, 0.5, 1].map((f) => ({ v: max.value * f, y: py(max.value * f) })))
const fmt = (v: number) => v.toLocaleString('ru-RU', { maximumFractionDigits: props.decimals })
const xTicks = computed(() =>
  [0, 0.5, 1].map((f) => {
    const t = props.from + (props.to - props.from) * f
    const long = props.to - props.from > 36 * 3600_000
    return {
      x: px(t),
      label: new Date(t).toLocaleString('ru-RU', long ? { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' } : { hour: '2-digit', minute: '2-digit' }),
      anchor: f === 0 ? 'start' : f === 1 ? 'end' : 'middle',
    }
  }),
)
const hasData = computed(() => lines.value.some((l) => l.dots.length))
</script>

<template>
  <div>
    <p v-if="!hasData" class="py-8 text-center text-sm text-muted-foreground">Нет данных за выбранный период.</p>
    <svg v-else :viewBox="`0 0 ${W} ${H}`" class="w-full" role="img">
      <g v-for="t in yTicks" :key="t.y">
        <line :x1="PAD.l" :x2="W - PAD.r" :y1="t.y" :y2="t.y" class="stroke-border" stroke-width="1" />
        <text :x="PAD.l - 6" :y="t.y + 3" text-anchor="end" class="fill-muted-foreground" font-size="10">{{ fmt(t.v) }}{{ unit }}</text>
      </g>
      <text v-for="t in xTicks" :key="t.x" :x="t.x" :y="H - 5" :text-anchor="t.anchor" class="fill-muted-foreground" font-size="10">{{ t.label }}</text>
      <line v-if="threshold != null" :x1="PAD.l" :x2="W - PAD.r" :y1="py(threshold)" :y2="py(threshold)" class="stroke-destructive" stroke-dasharray="4 3" stroke-width="1" />
      <template v-for="(l, i) in lines" :key="l.name">
        <polyline v-for="(s, j) in l.segs" :key="j" :points="s" fill="none" :class="COLORS[i % 5]" stroke-width="2" stroke-linejoin="round" />
        <circle
          v-for="d in l.dots"
          :key="d.t"
          :cx="d.x"
          :cy="d.y"
          r="2"
          :class="FILLS[i % 5]"
        >
          <title>{{ l.name }}: {{ fmt(d.v) }}{{ unit }} · {{ new Date(d.t).toLocaleString('ru-RU') }}</title>
        </circle>
      </template>
    </svg>
    <div v-if="series.length > 1" class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
      <span v-for="(s, i) in series" :key="s.name" class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full" :class="FILLS[i % 5].replace('fill-', 'bg-')" />{{ s.name }}</span>
    </div>
    <p v-if="threshold != null && hasData" class="text-xs text-muted-foreground">Пунктир — порог ({{ fmt(threshold) }}{{ unit }}).</p>
  </div>
</template>
