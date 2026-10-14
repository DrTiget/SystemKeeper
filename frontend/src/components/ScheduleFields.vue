<script setup lang="ts">
import SchedulePicker from '@/components/SchedulePicker.vue'
import ToggleSwitch from '@/components/form/ToggleSwitch.vue'
import { formatDate } from '@/lib/ui'

/** Автозапуск бекапа: включатель, расписание и время следующего запуска (в поясе расписания). */
const enabled = defineModel<boolean>('enabled', { required: true })
const schedule = defineModel<string>('schedule', { required: true })
defineProps<{
  timezone?: string
  /** Следующий запуск по сохранённым настройкам */
  nextRunAt?: string | null
  /** Расписание или включатель изменены и ещё не сохранены */
  changed?: boolean
  errors?: string[]
}>()
</script>

<template>
  <ToggleSwitch v-model="enabled" label="Запускать автоматически">
    <template #hint>
      <template v-if="!enabled">Выключено — бекап делается только вручную.</template>
      <template v-else-if="changed">Следующий запуск рассчитается после сохранения.</template>
      <template v-else-if="nextRunAt">Следующий запуск — <span class="text-foreground">{{ formatDate(nextRunAt, timezone) }}</span></template>
    </template>
  </ToggleSwitch>
  <template v-if="enabled">
    <SchedulePicker v-model="schedule" :invalid="!!errors?.length" />
    <p v-if="timezone" class="-mt-2 text-xs text-muted-foreground">Часовой пояс расписания — {{ timezone }}</p>
  </template>
  <p v-for="m in errors" :key="m" class="text-sm text-destructive">{{ m }}</p>
</template>
