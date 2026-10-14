<script setup lang="ts">
/** Вкладки раздела «Алерты»: срабатывания, правила, каналы. */
const tabs = [
  { to: '/alerts', label: 'Срабатывания', exact: true },
  { to: '/alerts/rules', label: 'Правила' },
  { to: '/alerts/channels', label: 'Каналы' },
]
</script>

<template>
  <nav class="flex gap-1 border-b">
    <RouterLink
      v-for="t in tabs"
      :key="t.to"
      v-slot="{ href, navigate, isActive, isExactActive }"
      :to="t.to"
      custom
    >
      <a
        :href="href"
        class="-mb-px border-b-2 px-3 py-2 text-sm transition-colors"
        :class="(t.exact ? isExactActive : isActive) ? 'border-primary font-medium text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'"
        @click="navigate"
      >
        {{ t.label }}
      </a>
    </RouterLink>
  </nav>
</template>
