<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import { type BackupStorage, type Retention, STORAGE_TYPE_LABELS } from '@/api/storages'
import SelectMenu from '@/components/SelectMenu.vue'
import { formatBytes, inputClass } from '@/lib/ui'

/** Где хранить бекапы, сколько копий держать и предельный объём. */
const model = defineModel<Retention>({ required: true })
defineProps<{ errors?: Record<string, string[]> }>()

const UNITS = { МБ: 1024 ** 2, ГБ: 1024 ** 3 } as const
type Unit = keyof typeof UNITS

// Лимит редактируется числом в МБ или ГБ; пусто — без ограничения
const unit = ref<Unit>('ГБ')
const amount = ref<number | ''>('')

function fromModel() {
  const b = model.value.max_size
  if (b === null) {
    amount.value = ''
    return
  }
  unit.value = b % UNITS['ГБ'] === 0 || b >= UNITS['ГБ'] ? 'ГБ' : 'МБ'
  amount.value = Math.round((b / UNITS[unit.value]) * 100) / 100
}
fromModel()
watch(() => model.value.max_size, (b) => {
  const cur = amount.value === '' ? null : Math.round(Number(amount.value) * UNITS[unit.value])
  if (cur !== b) fromModel()
})
watch([amount, unit], () => {
  model.value = { ...model.value, max_size: amount.value === '' || !Number(amount.value) ? null : Math.round(Number(amount.value) * UNITS[unit.value]) }
})

const storages = ref<BackupStorage[]>([])
onMounted(async () => {
  try {
    storages.value = (await api<{ data: BackupStorage[] }>('/storages')).data
  } catch {
    // список не загрузился — останется хранилище по умолчанию
  }
})
const storageOptions = computed(() =>
  storages.value.map((s) => ({
    value: s.id,
    label: s.name,
    hint: `${STORAGE_TYPE_LABELS[s.type]}${s.disk ? ` · свободно ${formatBytes(s.disk.free)}` : ''}`,
  })),
)
const storageId = computed({
  get: () => model.value.storage_id ?? storages.value.find((s) => s.is_default)?.id ?? null,
  set: (v) => (model.value = { ...model.value, storage_id: v }),
})

const label = 'text-sm leading-none font-medium'
const hint = 'text-sm text-muted-foreground'
const unitBtn = 'h-9 px-3 text-sm first:rounded-l-md last:rounded-r-md border -ml-px first:ml-0'
</script>

<template>
  <div class="grid gap-4">
    <div v-if="storages.length > 1" class="grid gap-2">
      <span :class="label">Куда сохранять</span>
      <SelectMenu v-model="storageId as number" :options="storageOptions" />
    </div>
    <p v-else-if="storages.length" :class="hint">
      Сохраняются в <b class="text-foreground">{{ storages[0]!.name }}</b>
      <template v-if="storages[0]!.disk"> — свободно {{ formatBytes(storages[0]!.disk.free) }} из {{ formatBytes(storages[0]!.disk.total) }}</template>
    </p>

    <div class="grid gap-4 sm:grid-cols-2">
      <div class="grid content-start gap-2">
        <label for="r-keep" :class="label">Сколько бекапов хранить</label>
        <input id="r-keep" v-model.number="model.keep_count" type="number" min="1" max="1000" required :class="inputClass" class="w-32!" :aria-invalid="!!errors?.keep_count" />
        <p :class="hint">Последние успешные; более старые удаляются после нового бекапа.</p>
        <p v-for="m in errors?.keep_count" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
      <div class="grid content-start gap-2">
        <label for="r-max" :class="label">Лимит объёма</label>
        <div class="flex gap-2">
          <input id="r-max" v-model="amount" type="number" min="1" step="any" placeholder="Без лимита" :class="inputClass" class="w-32!" :aria-invalid="!!errors?.max_size" />
          <div class="flex">
            <button v-for="u in Object.keys(UNITS) as Unit[]" :key="u" type="button" :class="[unitBtn, unit === u ? 'border-primary bg-accent' : 'border-input']" @click="unit = u">{{ u }}</button>
          </div>
        </div>
        <p :class="hint">Все бекапы вместе; при превышении удаляются самые старые, последний — никогда.</p>
        <p v-for="m in errors?.max_size" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
    </div>
  </div>
</template>
