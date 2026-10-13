<script setup lang="ts">
import { computed } from 'vue'

const ICONS: Record<string, string> = {
  logo: '<path d="M32 6 10 14v16c0 14 9.5 23.5 22 28 12.5-4.5 22-14 22-28V14L32 6Z" stroke="currentColor"/><path d="m22 32 7 7 13-14" stroke="#14d9b0"/>',
  logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
  panel: '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/>',
  loader: '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>',
}

const props = defineProps<{ name: string }>()
const isLogo = computed(() => props.name === 'logo')
</script>

<template>
  <svg
    xmlns="http://www.w3.org/2000/svg"
    :viewBox="isLogo ? '0 0 64 64' : '0 0 24 24'"
    fill="none"
    stroke="currentColor"
    :stroke-width="isLogo ? 7 : 2"
    stroke-linecap="round"
    stroke-linejoin="round"
    class="size-4 shrink-0"
    aria-hidden="true"
    v-html="ICONS[name] ?? ''"
  />
</template>
