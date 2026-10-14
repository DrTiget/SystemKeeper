<script setup lang="ts">
import { useRouter } from 'vue-router'
import type { Server } from '@/api/servers'
import Icon from '@/components/Icon.vue'
import { describeCron } from '@/lib/cron'
import { formatDate } from '@/lib/ui'

withDefaults(defineProps<{ items: Server[]; emptyText?: string; showProject?: boolean }>(), { emptyText: 'Пусто', showProject: true })
const router = useRouter()
const pluralDirs = (n: number) => (n % 10 === 1 && n % 100 !== 11 ? 'путь' : [2, 3, 4].includes(n % 10) && ![12, 13, 14].includes(n % 100) ? 'пути' : 'путей')
</script>

<template>
  <div class="overflow-x-auto rounded-xl border bg-card">
    <table class="w-full text-sm">
      <thead class="border-b text-left text-muted-foreground">
        <tr>
          <th class="px-4 py-3 font-medium">Название</th>
          <th class="px-4 py-3 font-medium">Подключение</th>
          <th class="px-4 py-3 font-medium">SSH</th>
          <th class="px-4 py-3 font-medium">Бекап</th>
          <th class="w-8 px-2" />
        </tr>
      </thead>
      <tbody class="divide-y">
        <tr v-for="s in items" :key="s.id" class="cursor-pointer hover:bg-accent/40" @click="router.push({ name: 'server', params: { id: s.id } })">
          <td class="px-4 py-3">
            <RouterLink :to="{ name: 'server', params: { id: s.id } }" class="font-medium hover:underline" @click.stop>{{ s.name }}</RouterLink>
            <RouterLink v-if="showProject && s.project" :to="{ name: 'project', params: { id: s.project.id } }" class="ml-2 rounded-md border px-1.5 py-0.5 text-xs text-muted-foreground hover:text-foreground" @click.stop>{{ s.project.name }}</RouterLink>
            <div v-if="s.description" class="max-w-xs truncate text-xs text-muted-foreground">{{ s.description }}</div>
          </td>
          <td class="px-4 py-3 whitespace-nowrap">
            <div class="font-mono text-xs">{{ s.username }}@{{ s.host }}{{ s.port === 22 ? '' : `:${s.port}` }}</div>
            <div class="text-xs text-muted-foreground">{{ s.auth_type === 'key' ? 'по ключу' : 'по паролю' }}</div>
          </td>
          <td class="px-4 py-3">
            <span v-if="s.last_check.status === 'ok'" class="inline-flex items-center gap-1.5" :title="`Проверено ${formatDate(s.last_check.checked_at)}`">
              <span class="size-2 rounded-full bg-emerald-500" />Работает
            </span>
            <span v-else-if="s.last_check.status === 'failed'" class="inline-flex max-w-56 items-center gap-1.5 text-destructive" :title="s.last_check.error ?? ''">
              <span class="size-2 shrink-0 rounded-full bg-destructive" /><span class="truncate">{{ s.last_check.error }}</span>
            </span>
            <span v-else class="text-muted-foreground">Не проверялся</span>
          </td>
          <td class="px-4 py-3">
            <template v-if="s.backup?.enabled && s.backup.schedule">
              <div class="whitespace-nowrap">{{ describeCron(s.backup.schedule) }}</div>
              <div class="text-xs text-muted-foreground">
                {{ s.backup.paths_count }} {{ pluralDirs(s.backup.paths_count) }}<template v-if="s.backup.next_run_at"> · след. {{ formatDate(s.backup.next_run_at) }}</template>
              </div>
            </template>
            <span v-else class="text-muted-foreground">Не настроен</span>
          </td>
          <td class="px-2 text-right text-muted-foreground whitespace-nowrap" @click.stop><slot name="actions" :item="s" /><Icon name="chevron-right" class="ml-1 inline size-4" /></td>
        </tr>
        <tr v-if="!items.length">
          <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">{{ emptyText }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
