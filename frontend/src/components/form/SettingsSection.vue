<script setup lang="ts">
import { ref } from 'vue'

/**
 * Раздел формы настроек: слева — заголовок и пояснение, справа — поля.
 * collapsible — поля скрыты за кратким итогом (summary), раскрываются кнопкой «Настроить»
 * (или сами, если forceOpen — например, в них ошибка).
 */
const props = defineProps<{ title: string; description?: string; collapsible?: boolean; summary?: string; forceOpen?: boolean }>()
const open = ref(!props.collapsible)
</script>

<template>
  <section class="grid gap-x-8 gap-y-3 border-t py-6 first:border-t-0 first:pt-0 last:pb-0 md:grid-cols-[13rem_minmax(0,1fr)]">
    <div class="grid content-start gap-1">
      <h4 class="text-sm font-semibold">{{ title }}</h4>
      <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
    </div>
    <div class="grid min-w-0 content-start gap-4">
      <div v-if="!open && !forceOpen" class="flex flex-wrap items-center justify-between gap-3">
        <span class="text-sm text-muted-foreground">{{ summary }}</span>
        <button type="button" class="text-sm font-medium text-primary hover:underline" @click="open = true">Настроить</button>
      </div>
      <slot v-else />
    </div>
  </section>
</template>
