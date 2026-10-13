<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue'
import Icon from '@/components/Icon.vue'

defineProps<{ title: string }>()
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
      <div role="dialog" aria-modal="true" class="w-full max-w-md rounded-xl border bg-background p-6 shadow-lg">
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
