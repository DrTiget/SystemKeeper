<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { type Heartbeat, MONITOR_STATUS } from '@/api/monitors'
import { formatDate } from '@/lib/ui'

/**
 * Время ответа по проверкам: линия с заливкой, недоступность — красные метки у оси времени.
 * Одна серия — без легенды; при наведении — перекрестие и подсказка ближайшей проверки.
 */
const props = defineProps<{ beats: Heartbeat[]; from: string; to: string }>()

const H = 200
const PAD = { top: 12, right: 12, bottom: 24, left: 48 }

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
const points = computed(() => props.beats.map((b) => ({ b, t: new Date(b.at).getTime() })).filter((p) => p.t >= t0.value && p.t <= t1.value))

/** Верх шкалы — «круглое» число над максимумом */
const yMax = computed(() => {
  const max = Math.max(0, ...points.value.map((p) => p.b.response_ms ?? 0))
  if (!max) return 100
  const step = 10 ** Math.floor(Math.log10(max))
  return Math.ceil((max * 1.1) / step) * step
})
const x = (t: number) => PAD.left + ((t - t0.value) / Math.max(1, t1.value - t0.value)) * (width.value - PAD.left - PAD.right)
const y = (ms: number) => PAD.top + (1 - ms / yMax.value) * (H - PAD.top - PAD.bottom)
const baseline = H - PAD.bottom

/** Отрезки линии: разрываются на проверках без ответа */
const segments = computed(() => {
  const out: { x: number; y: number }[][] = []
  let cur: { x: number; y: number }[] = []
  for (const p of points.value) {
    if (p.b.response_ms === null) {
      if (cur.length) out.push(cur)
      cur = []
    } else cur.push({ x: x(p.t), y: y(p.b.response_ms) })
  }
  if (cur.length) out.push(cur)
  return out
})
const linePath = (s: { x: number; y: number }[]) => s.map((p, i) => `${i ? 'L' : 'M'}${p.x.toFixed(1)},${p.y.toFixed(1)}`).join('')
const areaPath = (s: { x: number; y: number }[]) => `${linePath(s)}L${s.at(-1)!.x.toFixed(1)},${baseline}L${s[0]!.x.toFixed(1)},${baseline}Z`

const downs = computed(() => points.value.filter((p) => p.b.status === 'down').map((p) => x(p.t)))
const yTicks = computed(() => [0, 0.5, 1].map((k) => Math.round(yMax.value * k)))
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

// Наведение: ближайшая по времени проверка
const hover = ref<{ x: number; b: Heartbeat } | null>(null)
function onMove(e: PointerEvent) {
  const rect = (e.currentTarget as SVGElement).getBoundingClientRect()
  const px = e.clientX - rect.left
  let best: { x: number; b: Heartbeat } | null = null
  for (const p of points.value) {
    const px2 = x(p.t)
    if (!best || Math.abs(px2 - px) < Math.abs(best.x - px)) best = { x: px2, b: p.b }
  }
  hover.value = best
}
</script>

<template>
  <div ref="el" class="relative">
    <p v-if="!points.length" class="flex h-[200px] items-center justify-center text-sm text-muted-foreground">Проверок за этот период нет</p>
    <svg v-else :width="width" :height="H" class="block touch-none select-none" @pointermove="onMove" @pointerleave="hover = null">
      <g class="text-muted-foreground">
        <template v-for="t in yTicks" :key="t">
          <line :x1="PAD.left" :x2="width - PAD.right" :y1="y(t)" :y2="y(t)" stroke="currentColor" stroke-opacity="0.15" :stroke-dasharray="t ? '3 3' : undefined" />
          <text :x="PAD.left - 8" :y="y(t)" text-anchor="end" dominant-baseline="middle" class="fill-current text-[11px]">{{ t }} мс</text>
        </template>
        <text v-for="t in xTicks" :key="t.x" :x="t.x" :y="H - 6" text-anchor="middle" class="fill-current text-[11px]">{{ t.label }}</text>
      </g>
      <g class="text-primary">
        <path v-for="(s, i) in segments" :key="'a' + i" :d="areaPath(s)" fill="currentColor" fill-opacity="0.12" />
        <path v-for="(s, i) in segments" :key="'l' + i" :d="linePath(s)" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
      </g>
      <g class="text-destructive">
        <rect v-for="(dx, i) in downs" :key="i" :x="dx - 1.5" :y="baseline - 8" width="3" height="8" rx="1.5" fill="currentColor" />
      </g>
      <g v-if="hover" class="text-foreground">
        <line :x1="hover.x" :x2="hover.x" :y1="PAD.top" :y2="baseline" stroke="currentColor" stroke-opacity="0.3" />
        <circle
          v-if="hover.b.response_ms !== null"
          :cx="hover.x"
          :cy="y(hover.b.response_ms)"
          r="4"
          class="fill-primary stroke-card"
          stroke-width="2"
        />
      </g>
    </svg>
    <div
      v-if="hover"
      class="pointer-events-none absolute top-0 z-10 w-max max-w-64 rounded-md border bg-popover px-2.5 py-1.5 text-xs text-popover-foreground shadow-md"
      :style="hover.x > width / 2 ? { right: `${width - hover.x + 10}px` } : { left: `${hover.x + 10}px` }"
    >
      <div class="flex items-center gap-1.5 font-medium">
        <span class="size-2 rounded-full" :class="MONITOR_STATUS[hover.b.status].dot" />
        {{ hover.b.response_ms !== null ? `${hover.b.response_ms} мс` : MONITOR_STATUS[hover.b.status].label }}
      </div>
      <div class="text-muted-foreground">{{ formatDate(hover.b.at) }}</div>
      <div v-if="hover.b.message" class="mt-0.5 break-words">{{ hover.b.message }}</div>
    </div>
  </div>
</template>
