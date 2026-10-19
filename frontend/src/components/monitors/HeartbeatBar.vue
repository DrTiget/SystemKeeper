<script setup lang="ts">
import { computed, ref } from 'vue'
import { type Heartbeat, MONITOR_STATUS } from '@/api/monitors'
import { formatDate } from '@/lib/ui'

/** Полоса последних проверок: старые слева, новые справа; пустые ячейки — проверок ещё не было. */
const props = withDefaults(defineProps<{ beats: Heartbeat[]; slots?: number; size?: 'sm' | 'md'; selectable?: boolean }>(), { slots: 50, size: 'sm' })
const emit = defineEmits<{ select: [beat: Heartbeat] }>()

const cells = computed(() => {
  const last = props.beats.slice(-props.slots)
  return [...Array<Heartbeat | null>(props.slots - last.length).fill(null), ...last]
})
const hovered = ref<number | null>(null)
const tip = computed(() => (hovered.value === null ? null : cells.value[hovered.value]))
</script>

<template>
  <div class="relative" @mouseleave="hovered = null">
    <div class="flex items-end gap-[2px]" :class="size === 'md' ? 'h-8' : 'h-6'" role="img" :aria-label="`Последние проверки: ${beats.length}`">
      <span
        v-for="(b, i) in cells"
        :key="i"
        class="h-full min-w-[3px] flex-1 rounded-[3px] transition-opacity"
        :class="[b ? MONITOR_STATUS[b.status].bar : 'bg-muted', hovered !== null && hovered !== i && 'opacity-50', selectable && b && 'cursor-pointer']"
        @mouseenter="hovered = b ? i : null"
        @click="selectable && b && emit('select', b)"
      />
    </div>
    <div
      v-if="tip"
      class="pointer-events-none absolute bottom-full z-20 mb-2 w-max max-w-64 -translate-x-1/2 rounded-md border bg-popover px-2.5 py-1.5 text-xs text-popover-foreground shadow-md"
      :style="{ left: `${((hovered! + 0.5) / slots) * 100}%` }"
    >
      <div class="flex items-center gap-1.5 font-medium">
        <span class="size-2 rounded-full" :class="MONITOR_STATUS[tip.status].dot" />{{ MONITOR_STATUS[tip.status].label }}
        <span v-if="tip.response_ms !== null" class="font-normal text-muted-foreground">· {{ tip.response_ms }} мс</span>
      </div>
      <div class="text-muted-foreground">{{ formatDate(tip.at) }}</div>
      <div v-if="tip.message" class="mt-0.5 break-words">{{ tip.message }}</div>
    </div>
  </div>
</template>
