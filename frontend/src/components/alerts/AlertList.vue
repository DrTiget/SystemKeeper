<script setup lang="ts">
import { ref } from 'vue'
import { type Alert, CHANNEL_TYPES, OBJECT_LABELS, objectRoute, SEVERITY } from '@/api/alerts'
import { formatSpan } from '@/api/monitors'
import Icon from '@/components/Icon.vue'
import { btnClass, formatDate } from '@/lib/ui'

/** Список срабатываний: серьёзность, что случилось, где, сколько длится; раскрывается журнал уведомлений. */
withDefaults(defineProps<{ items: Alert[]; showProject?: boolean; compact?: boolean }>(), { showProject: true })
const emit = defineEmits<{ ack: [alert: Alert] }>()
const expanded = ref<number | null>(null)
</script>

<template>
  <ul class="divide-y rounded-xl border bg-card text-sm">
    <li v-for="a in items" :key="a.id">
      <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
        <span class="inline-flex w-32 shrink-0 items-center gap-1.5 rounded-md border px-2 py-0.5 text-xs font-medium" :class="a.status === 'firing' ? SEVERITY[a.severity].badge : 'text-muted-foreground'">
          <span class="size-1.5 rounded-full" :class="a.status === 'firing' ? [SEVERITY[a.severity].dot, 'animate-pulse'] : 'bg-emerald-500'" />
          {{ a.status === 'firing' ? SEVERITY[a.severity].label : 'Решено' }}
        </span>
        <button type="button" class="grid min-w-0 flex-1 gap-0.5 text-left" @click="expanded = expanded === a.id ? null : a.id">
          <span class="font-medium" :class="a.status === 'resolved' && 'text-muted-foreground'">{{ a.message }}</span>
          <span class="flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground">
            <Icon :name="OBJECT_LABELS[a.object.type].icon" class="size-3.5" />
            <RouterLink :to="objectRoute(a.object)" class="hover:text-foreground hover:underline" @click.stop>{{ a.object.name }}</RouterLink>
            <template v-if="showProject && a.object.project">· {{ a.object.project.name }}</template>
            · правило «{{ a.rule.name }}»
          </span>
        </button>
        <span class="grid justify-items-end text-xs whitespace-nowrap">
          <span class="tabular-nums" :class="a.status === 'firing' && 'font-medium'">{{ a.status === 'firing' ? `уже ${formatSpan(a.started_at)}` : formatSpan(a.started_at, a.resolved_at) }}</span>
          <span class="text-muted-foreground">{{ formatDate(a.started_at) }}</span>
        </span>
        <div v-if="!compact" class="flex w-28 justify-end">
          <button v-if="a.status === 'firing' && !a.acknowledged_at" :class="btnClass.outline" class="h-8! px-3!" title="Повторные уведомления по нему не придут" @click="emit('ack', a)">
            <Icon name="check" />Принято
          </button>
          <span v-else-if="a.acknowledged_at" class="truncate text-xs text-muted-foreground" :title="`Принято ${formatDate(a.acknowledged_at)}`">принял {{ a.acknowledged_by?.name ?? '—' }}</span>
        </div>
        <button type="button" class="text-muted-foreground" :title="expanded === a.id ? 'Свернуть' : 'Уведомления'" @click="expanded = expanded === a.id ? null : a.id">
          <Icon name="chevron-right" class="size-4 transition-transform" :class="expanded === a.id && 'rotate-90'" />
        </button>
      </div>
      <div v-if="expanded === a.id" class="grid gap-1.5 border-t bg-muted/30 px-4 py-3 text-xs">
        <p v-if="a.resolved_at" class="text-muted-foreground">Решено {{ formatDate(a.resolved_at) }}</p>
        <p v-if="!a.notifications.length" class="text-muted-foreground">Уведомления не отправлялись — у правила нет каналов.</p>
        <div v-for="(n, i) in a.notifications" :key="i" class="flex flex-wrap items-center gap-x-2">
          <Icon :name="CHANNEL_TYPES[n.channel.type].icon" class="size-3.5 text-muted-foreground" />
          <span>{{ n.channel.name }}</span>
          <span class="text-muted-foreground">{{ formatDate(n.at) }}</span>
          <span v-if="n.ok" class="text-emerald-600 dark:text-emerald-400">доставлено</span>
          <span v-else class="text-destructive">не доставлено: {{ n.error }}</span>
        </div>
      </div>
    </li>
  </ul>
</template>
