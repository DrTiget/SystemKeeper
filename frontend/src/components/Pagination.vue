<script setup lang="ts">
import { btnClass } from '@/lib/format'

defineProps<{ page: number; lastPage: number; total: number }>()
const emit = defineEmits<{ change: [page: number] }>()
</script>

<template>
  <div v-if="lastPage > 1" class="flex items-center justify-between gap-4 text-sm text-muted-foreground">
    <span>Всего: {{ total }}</span>
    <div class="flex items-center gap-2">
      <button :class="btnClass.outlineSm" :disabled="page <= 1" @click="emit('change', page - 1)">Назад</button>
      <span class="tabular-nums">{{ page }} / {{ lastPage }}</span>
      <button :class="btnClass.outlineSm" :disabled="page >= lastPage" @click="emit('change', page + 1)">Вперёд</button>
    </div>
  </div>
</template>
