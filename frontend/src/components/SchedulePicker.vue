<script setup lang="ts">
import { reactive, watch } from 'vue'
import SelectMenu from '@/components/SelectMenu.vue'
import SegmentedControl from '@/components/form/SegmentedControl.vue'
import { buildCron, parseCron, WEEKDAYS } from '@/lib/cron'
import { inputClass } from '@/lib/ui'

/** Выбор расписания: пресеты (час / день / неделя) или свой cron. v-model — cron-выражение. */
const model = defineModel<string>({ required: true })
defineProps<{ invalid?: boolean }>()

type Kind = 'hourly' | 'daily' | 'weekly' | 'custom'
const s = reactive({ kind: 'daily' as Kind, time: '03:00', minute: 0, weekday: 1, cron: '0 3 * * *' })
const kinds: { value: Kind; label: string }[] = [
  { value: 'hourly', label: 'Каждый час' },
  { value: 'daily', label: 'Каждый день' },
  { value: 'weekly', label: 'Раз в неделю' },
  { value: 'custom', label: 'Свой cron' },
]
const weekdayOptions = [1, 2, 3, 4, 5, 6, 0].map((d) => ({ value: d, label: WEEKDAYS[d]! }))

function build(): string {
  const [h, m] = s.time.split(':').map(Number) as [number, number]
  switch (s.kind) {
    case 'hourly':
      return buildCron({ kind: 'hourly', minute: s.minute || 0 })
    case 'daily':
      return buildCron({ kind: 'daily', hour: h, minute: m })
    case 'weekly':
      return buildCron({ kind: 'weekly', weekday: s.weekday, hour: h, minute: m })
    default:
      return buildCron({ kind: 'custom', cron: s.cron })
  }
}

function apply(value: string) {
  const p = parseCron(value)
  s.cron = value
  s.kind = p.kind
  if (p.kind === 'hourly') s.minute = p.minute
  if (p.kind === 'daily' || p.kind === 'weekly') s.time = `${String(p.hour).padStart(2, '0')}:${String(p.minute).padStart(2, '0')}`
  if (p.kind === 'weekly') s.weekday = p.weekday
}

apply(model.value)
watch(model, (v) => {
  if (v !== build()) apply(v)
})
watch(s, () => {
  if (s.kind !== 'custom') s.cron = build()
  model.value = build()
})
</script>

<template>
  <div class="grid gap-3">
    <SegmentedControl v-model="s.kind" :options="kinds" />
    <div v-if="s.kind === 'hourly'" class="flex flex-wrap items-center gap-2 text-sm">
      в <input v-model.number="s.minute" type="number" min="0" max="59" required aria-label="Минута" :class="inputClass" class="w-20!" /> минут каждого часа
    </div>
    <div v-else-if="s.kind === 'daily'" class="flex flex-wrap items-center gap-2 text-sm">
      в <input v-model="s.time" type="time" required aria-label="Время" :class="inputClass" class="w-32!" />
    </div>
    <div v-else-if="s.kind === 'weekly'" class="flex flex-wrap items-center gap-2 text-sm">
      <SelectMenu v-model="s.weekday" :options="weekdayOptions" class="w-48" />
      в <input v-model="s.time" type="time" required aria-label="Время" :class="inputClass" class="w-32!" />
    </div>
    <div v-else class="grid gap-1">
      <input v-model="s.cron" required placeholder="0 3 * * *" spellcheck="false" aria-label="Cron-выражение" :class="inputClass" class="max-w-xs font-mono" :aria-invalid="invalid" />
      <p class="text-sm text-muted-foreground">Минута, час, день месяца, месяц, день недели.</p>
    </div>
  </div>
</template>
