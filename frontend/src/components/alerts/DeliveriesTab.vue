<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/api/client'
import type { AlertDelivery, Paginated } from '@/api/types'
import Pagination from '@/components/Pagination.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { DELIVERY_STATUS, EVENTS, eventLabel } from '@/lib/alerts'
import { btnClass, errorMessage, formatDate, inputClass } from '@/lib/format'

const items = ref<AlertDelivery[]>([])
const meta = ref({ current_page: 1, last_page: 1, per_page: 20, total: 0 })
const status = ref('')
const event = ref('')
const loading = ref(true)
const loadError = ref('')

async function load(page = meta.value.current_page) {
  loading.value = true
  loadError.value = ''
  const q = new URLSearchParams({ page: String(page), per_page: '20' })
  if (status.value) q.set('status', status.value)
  if (event.value) q.set('event', event.value)
  try {
    const res = await api<Paginated<AlertDelivery>>(`/alert-deliveries?${q}`)
    if (!res.data.length && page > res.meta.last_page && res.meta.last_page > 0) return load(res.meta.last_page)
    items.value = res.data
    meta.value = res.meta
  } catch (e) {
    loadError.value = errorMessage(e, 'Не удалось загрузить журнал')
  } finally {
    loading.value = false
  }
}
onMounted(() => load(1))
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center gap-2">
      <select v-model="status" :class="inputClass" class="w-48" aria-label="Статус" @change="load(1)">
        <option value="">Все статусы</option><option v-for="(s, k) in DELIVERY_STATUS" :key="k" :value="k">{{ s.label }}</option>
      </select>
      <select v-model="event" :class="inputClass" class="w-72" aria-label="Событие" @change="load(1)">
        <option value="">Все события</option><option v-for="(label, k) in EVENTS" :key="k" :value="k">{{ label }}</option>
      </select>
      <button :class="[btnClass.outline, 'ml-auto']" @click="load()">Обновить</button>
    </div>
    <div v-if="loadError" role="alert" class="flex items-center gap-3 rounded-lg border bg-card px-4 py-3 text-sm text-destructive">
      {{ loadError }}<button :class="[btnClass.outlineSm, 'ml-auto']" @click="load()">Повторить</button>
    </div>
    <div v-else-if="loading && !items.length" class="rounded-xl border p-10 text-center text-sm text-muted-foreground">Загрузка…</div>
    <div v-else-if="!items.length" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">Отправок нет.</div>
    <template v-else>
      <div class="overflow-x-auto rounded-xl border" :class="loading && 'opacity-60'">
        <table class="w-full text-sm">
          <thead class="border-b text-left text-xs text-muted-foreground">
            <tr>
              <th class="px-4 py-2 font-medium">Время</th>
              <th class="px-4 py-2 font-medium">Статус</th>
              <th class="px-4 py-2 font-medium">Событие</th>
              <th class="px-4 py-2 font-medium">Сообщение</th>
              <th class="px-4 py-2 font-medium">Попытки</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in items" :key="d.id" class="border-b last:border-0 align-top">
              <td class="px-4 py-2 whitespace-nowrap tabular-nums">{{ formatDate(d.sent_at ?? d.created_at) }}</td>
              <td class="px-4 py-2"><StatusBadge :tone="DELIVERY_STATUS[d.status].tone" :label="DELIVERY_STATUS[d.status].label" /></td>
              <td class="px-4 py-2">{{ eventLabel(d.event) }}</td>
              <td class="max-w-md px-4 py-2 break-words">
                <span class="block font-medium">{{ d.title }}</span>
                <span v-if="d.body" class="block text-muted-foreground">{{ d.body }}</span>
                <span v-if="d.error" class="block text-destructive">{{ d.error }}</span>
              </td>
              <td class="px-4 py-2 tabular-nums">{{ d.attempts }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pagination :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="load" />
    </template>
  </div>
</template>
