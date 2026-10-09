<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'
import Icon from '@/components/Icon.vue'

defineProps<{ title: string; description?: string; wide?: boolean }>()
const emit = defineEmits<{ close: [] }>()

const onKey = (e: KeyboardEvent) => e.key === 'Escape' && emit('close')
onMounted(() => document.addEventListener('keydown', onKey))
onUnmounted(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/80 p-4" @mousedown.self="emit('close')">
      <div
        role="dialog"
        aria-modal="true"
        class="relative my-auto grid w-full gap-4 rounded-lg border bg-background p-6 shadow-lg animate-in fade-in-0 zoom-in-95"
        :class="wide ? 'sm:max-w-xl' : 'sm:max-w-lg'"
      >
        <div class="space-y-1.5">
          <h3 class="text-lg leading-none font-semibold">{{ title }}</h3>
          <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
        </div>
        <button class="absolute top-4 right-4 rounded-sm opacity-70 hover:opacity-100" title="Закрыть" @click="emit('close')"><Icon name="x" /></button>
        <slot />
      </div>
    </div>
  </Teleport>
</template>
