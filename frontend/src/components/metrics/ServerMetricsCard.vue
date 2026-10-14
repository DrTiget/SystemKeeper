<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/api/client'
import { METRICS_STATUS, pct, type ServerMetrics } from '@/api/metrics'
import Icon from '@/components/Icon.vue'
import UsageMeter from '@/components/metrics/UsageMeter.vue'
import { formatBytes } from '@/lib/ui'

/** Сводка ресурсов на странице сервера; если модуль мониторинга недоступен — карточки нет. */
const props = defineProps<{ serverId: number }>()
const m = ref<ServerMetrics | null>(null)
onMounted(async () => {
  m.value = await api<{ data: ServerMetrics }>(`/servers/${props.serverId}/metrics`).then((r) => r.data).catch(() => null)
})
const fullest = () => [...m.value!.disks].sort((a, b) => pct(b.used, b.total) - pct(a.used, a.total))[0]
</script>

<template>
  <section v-if="m" class="grid gap-4 rounded-xl border bg-card p-4 shadow-xs">
    <div class="flex items-center justify-between gap-2">
      <h3 class="flex items-center gap-2 font-semibold">
        Ресурсы
        <span class="size-2 rounded-full" :class="METRICS_STATUS[m.status].dot" :title="METRICS_STATUS[m.status].label" />
      </h3>
      <RouterLink :to="{ name: 'server-metrics', params: { id: serverId } }" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">Графики<Icon name="chevron-right" class="size-4" /></RouterLink>
    </div>
    <p v-if="m.status === 'disabled'" class="text-sm text-muted-foreground">Сбор показателей выключен.</p>
    <p v-else-if="m.status === 'unreachable'" class="text-sm break-words text-destructive">{{ m.error ?? 'Сервер не отвечает' }}</p>
    <template v-else>
      <UsageMeter label="CPU" :value="m.cpu" />
      <UsageMeter label="Память" :value="m.memory ? pct(m.memory.used, m.memory.total) : null" :detail="m.memory ? `${formatBytes(m.memory.used)} из ${formatBytes(m.memory.total)}` : undefined" />
      <UsageMeter v-if="fullest()" :label="`Диск ${fullest()!.mount}`" :value="pct(fullest()!.used, fullest()!.total)" :detail="`свободно ${formatBytes(fullest()!.total - fullest()!.used)}`" />
    </template>
  </section>
</template>
