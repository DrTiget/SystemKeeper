<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import Icon from '@/components/Icon.vue'

withDefaults(defineProps<{ title: string; wide?: boolean }>(), { wide: false })
const emit = defineEmits<{ close: [] }>()

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('close')
}
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @pointerdown.self="emit('close')">
      <div role="dialog" aria-modal="true" class="max-h-[calc(100svh-2rem)] w-full overflow-y-auto rounded-xl border bg-background p-6 shadow-lg" :class="wide ? 'max-w-xl' : 'max-w-md'">
        <div class="mb-4 flex items-center justify-between gap-3">
          <h3 class="text-lg font-semibold">{{ title }}</h3>
          <button type="button" class="inline-flex size-7 items-center justify-center rounded-md hover:bg-accent" title="Закрыть" @click="emit('close')">
            <Icon name="x" />
          </button>
        </div>
        <slot />
      </div>
    </div>
  </Teleport>
</template>
