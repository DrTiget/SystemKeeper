<script setup lang="ts">
import { reactive, watch } from 'vue'
import SelectMenu from '@/components/SelectMenu.vue'
import { buildCron, parseCron, WEEKDAYS } from '@/lib/cron'
import { inputClass } from '@/lib/ui'

/** Выбор расписания: пресеты (час / день / неделя) или свой cron. v-model — cron-выражение. */
const model = defineModel<string>({ required: true })
defineProps<{ timezone?: string; invalid?: boolean }>()

type Kind = 'hourly' | 'daily' | 'weekly' | 'custom'
const s = reactive({ kind: 'daily' as Kind, time: '03:00', minute: 0, weekday: 1, cron: '0 3 * * *' })
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

const segment = 'flex h-9 flex-1 cursor-pointer items-center justify-center rounded-md border px-2 text-sm whitespace-nowrap has-checked:border-primary has-checked:bg-accent has-checked:text-accent-foreground'
</script>

<template>
  <div class="grid gap-2">
    <div class="flex flex-wrap gap-2">
      <label :class="segment"><input v-model="s.kind" type="radio" value="hourly" class="sr-only" />Каждый час</label>
      <label :class="segment"><input v-model="s.kind" type="radio" value="daily" class="sr-only" />Каждый день</label>
      <label :class="segment"><input v-model="s.kind" type="radio" value="weekly" class="sr-only" />Раз в неделю</label>
      <label :class="segment"><input v-model="s.kind" type="radio" value="custom" class="sr-only" />Cron</label>
    </div>
    <div v-if="s.kind === 'hourly'" class="flex items-center gap-2 text-sm">
      В
      <input v-model.number="s.minute" type="number" min="0" max="59" required :class="inputClass" class="w-20!" />
      минут каждого часа
    </div>
    <div v-else-if="s.kind === 'daily'" class="flex items-center gap-2 text-sm">
      В <input v-model="s.time" type="time" required :class="inputClass" class="w-32!" />
    </div>
    <div v-else-if="s.kind === 'weekly'" class="flex items-center gap-2 text-sm">
      <SelectMenu v-model="s.weekday" :options="weekdayOptions" class="w-48" />
      в <input v-model="s.time" type="time" required :class="inputClass" class="w-32!" />
    </div>
    <div v-else class="grid gap-1">
      <input v-model="s.cron" required placeholder="0 3 * * *" spellcheck="false" :class="inputClass" class="font-mono" :aria-invalid="invalid" />
      <p class="text-sm text-muted-foreground">Минута, час, день месяца, месяц, день недели.</p>
    </div>
    <p v-if="timezone" class="text-sm text-muted-foreground">Время — {{ timezone }}.</p>
  </div>
</template>
