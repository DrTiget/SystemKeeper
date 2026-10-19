<script setup lang="ts">
import { describeTestResult, type RuleTestResult } from '@/api/alerts'
import Icon from '@/components/Icon.vue'

/** Итог тестовой отправки правила: строка на канал или общая ошибка (например, у правила нет каналов). */
defineProps<{ results: RuleTestResult[]; error?: string }>()
</script>

<template>
  <ul class="grid gap-1 text-sm" role="status">
    <li v-if="error" class="flex items-start gap-1.5 text-destructive"><Icon name="x" class="mt-0.5 size-4 shrink-0" /><span class="whitespace-pre-wrap">{{ error }}</span></li>
    <li v-for="r in results" :key="r.channel.id" class="flex items-start gap-1.5" :class="r.ok ? '' : 'text-destructive'">
      <Icon :name="r.ok ? 'check' : 'x'" class="mt-0.5 size-4 shrink-0" :class="r.ok && 'text-primary'" />
      <span class="min-w-0 whitespace-pre-wrap">{{ describeTestResult(r) }}</span>
    </li>
  </ul>
</template>
