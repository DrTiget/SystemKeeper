<script setup lang="ts">
import { useRouter } from 'vue-router'
import { type DatabaseConnection, ENGINE_LABELS } from '@/api/databases'
import Icon from '@/components/Icon.vue'
import { describeCron } from '@/lib/cron'
import { formatDate } from '@/lib/ui'

withDefaults(defineProps<{ items: DatabaseConnection[]; emptyText?: string; showProject?: boolean }>(), { emptyText: 'Пусто', showProject: true })
const router = useRouter()
const whatDumped = (c: DatabaseConnection) =>
  c.backup.databases.length ? `${c.backup.databases.length} ${c.backup.databases.length === 1 ? 'база' : c.backup.databases.length < 5 ? 'базы' : 'баз'}` : 'все базы'
</script>

<template>
  <div class="overflow-x-auto rounded-xl border bg-card">
    <table class="w-full text-sm">
      <thead class="border-b text-left text-muted-foreground">
        <tr>
          <th class="px-4 py-3 font-medium">Название</th>
          <th class="px-4 py-3 font-medium">Подключение</th>
          <th class="px-4 py-3 font-medium">Состояние</th>
          <th class="px-4 py-3 font-medium">Бекап</th>
          <th class="w-8 px-2" />
        </tr>
      </thead>
      <tbody class="divide-y">
        <tr v-for="c in items" :key="c.id" class="cursor-pointer hover:bg-accent/40" @click="router.push({ name: 'database', params: { id: c.id } })">
          <td class="px-4 py-3">
            <RouterLink :to="{ name: 'database', params: { id: c.id } }" class="font-medium hover:underline" @click.stop>{{ c.name }}</RouterLink>
            <RouterLink v-if="showProject && c.project" :to="{ name: 'project', params: { id: c.project.id } }" class="ml-2 rounded-md border px-1.5 py-0.5 text-xs text-muted-foreground hover:text-foreground" @click.stop>{{ c.project.name }}</RouterLink>
            <div class="text-xs text-muted-foreground">{{ ENGINE_LABELS[c.engine] }}<template v-if="c.last_check.server_version"> · {{ c.last_check.server_version.split(' ').slice(0, 2).join(' ') }}</template></div>
          </td>
          <td class="px-4 py-3 whitespace-nowrap">
            <div class="font-mono text-xs">{{ c.username }}@{{ c.host }}:{{ c.port }}</div>
            <div class="text-xs text-muted-foreground">
              <template v-if="c.via === 'ssh'">через {{ c.server?.name }}<template v-if="c.docker_container">, контейнер {{ c.docker_container }}</template></template>
              <template v-else>напрямую</template>
            </div>
          </td>
          <td class="px-4 py-3">
            <span v-if="c.last_check.status === 'ok'" class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-emerald-500" />Работает</span>
            <span v-else-if="c.last_check.status === 'failed'" class="inline-flex max-w-56 items-center gap-1.5 text-destructive" :title="c.last_check.error ?? ''">
              <span class="size-2 shrink-0 rounded-full bg-destructive" /><span class="truncate">{{ c.last_check.error }}</span>
            </span>
            <span v-else class="text-muted-foreground">Не проверялась</span>
          </td>
          <td class="px-4 py-3">
            <template v-if="c.backup.enabled">
              <div class="whitespace-nowrap">{{ describeCron(c.backup.schedule) }}</div>
              <div class="text-xs text-muted-foreground">{{ whatDumped(c) }}<template v-if="c.backup.next_run_at"> · след. {{ formatDate(c.backup.next_run_at) }}</template></div>
            </template>
            <span v-else class="text-muted-foreground">Не настроен</span>
          </td>
          <td class="px-2 text-right text-muted-foreground whitespace-nowrap" @click.stop><slot name="actions" :item="c" /><Icon name="chevron-right" class="ml-1 inline size-4" /></td>
        </tr>
        <tr v-if="!items.length">
          <td colspan="5" class="px-4 py-10 text-center text-muted-foreground">{{ emptyText }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
