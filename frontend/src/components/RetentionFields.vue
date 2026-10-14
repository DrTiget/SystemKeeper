<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import { type BackupStorage, type Retention, STORAGE_TYPE_LABELS } from '@/api/storages'
import Icon from '@/components/Icon.vue'
import SelectMenu from '@/components/SelectMenu.vue'
import SegmentedControl from '@/components/form/SegmentedControl.vue'
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

const unitOptions = (Object.keys(UNITS) as Unit[]).map((u) => ({ value: u, label: u }))
</script>

<template>
  <div class="grid gap-4">
    <div v-if="storages.length > 1" class="grid max-w-sm gap-2">
      <span class="text-sm font-medium">Куда сохранять</span>
      <SelectMenu v-model="storageId as number" :options="storageOptions" />
    </div>
    <p v-else-if="storages.length" class="flex items-center gap-2 text-sm text-muted-foreground">
      <Icon name="hard-drive" class="size-4" />
      <span>
        <span class="text-foreground">{{ storages[0]!.name }}</span>
        <template v-if="storages[0]!.disk"> — свободно {{ formatBytes(storages[0]!.disk.free) }} из {{ formatBytes(storages[0]!.disk.total) }}</template>
      </span>
    </p>

    <div class="grid gap-1.5">
      <div class="flex flex-wrap items-center gap-2 text-sm">
        <label for="r-keep">Хранить последние</label>
        <input id="r-keep" v-model.number="model.keep_count" type="number" min="1" max="1000" required :class="inputClass" class="w-20!" :aria-invalid="!!errors?.keep_count" />
        <span>бекапов</span>
      </div>
      <p class="text-xs text-muted-foreground">Считаются только успешные; более старые удаляются после очередного бекапа.</p>
      <p v-for="m in errors?.keep_count" :key="m" class="text-sm text-destructive">{{ m }}</p>
    </div>

    <div class="grid gap-1.5">
      <div class="flex flex-wrap items-center gap-2 text-sm">
        <label for="r-max">И занимать не больше</label>
        <input id="r-max" v-model="amount" type="number" min="1" step="any" placeholder="без лимита" :class="inputClass" class="w-32!" :aria-invalid="!!errors?.max_size" />
        <SegmentedControl v-model="unit" :options="unitOptions" />
      </div>
      <p class="text-xs text-muted-foreground">Все бекапы вместе. При превышении удаляются самые старые, последний бекап остаётся всегда.</p>
      <p v-for="m in errors?.max_size" :key="m" class="text-sm text-destructive">{{ m }}</p>
    </div>
  </div>
</template>
