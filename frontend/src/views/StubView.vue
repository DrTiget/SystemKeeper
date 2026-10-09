<script setup lang="ts">
import { useRoute } from 'vue-router'

const route = useRoute()
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="mb-2 space-y-0.5">
      <h2 class="text-xl font-semibold tracking-tight">{{ route.meta.title }}</h2>
    </header>
    <div class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">Скоро</div>
  </div>
</template>
