<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import { type BackupStorage, type Retention, STORAGE_TYPE_LABELS } from '@/api/storages'
import Icon from '@/components/Icon.vue'
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
// Куда сохранять — можно несколько мест; пустой список на сервере значит «место по умолчанию»
const selected = computed(() =>
  model.value.storage_ids.length ? model.value.storage_ids : storages.value.filter((s) => s.is_default).map((s) => s.id),
)
function toggleStorage(id: number) {
  const ids = selected.value.includes(id) ? selected.value.filter((x) => x !== id) : [...selected.value, id]
  if (!ids.length) return // хотя бы одно место
  model.value = { ...model.value, storage_ids: storages.value.filter((s) => ids.includes(s.id)).map((s) => s.id) }
}
const storageHint = (s: BackupStorage) =>
  s.disk ? `${STORAGE_TYPE_LABELS[s.type]} · свободно ${formatBytes(s.disk.free)}` : s.s3 ? `S3 · ${s.s3.bucket}${s.s3.prefix ? '/' + s.s3.prefix : ''}` : STORAGE_TYPE_LABELS[s.type]

const unitOptions = (Object.keys(UNITS) as Unit[]).map((u) => ({ value: u, label: u }))
</script>

<template>
  <div class="grid gap-4">
    <div v-if="storages.length > 1" class="grid gap-2">
      <span class="text-sm font-medium">Куда сохранять</span>
      <div class="grid gap-2 sm:grid-cols-2">
        <label
          v-for="s in storages"
          :key="s.id"
          class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 has-checked:border-primary has-checked:bg-accent/50"
          :class="selected.length === 1 && selected.includes(s.id) && 'cursor-default'"
        >
          <input type="checkbox" class="mt-0.5 size-4 accent-primary" :checked="selected.includes(s.id)" :disabled="selected.length === 1 && selected.includes(s.id)" @change="toggleStorage(s.id)" />
          <span class="grid min-w-0 gap-0.5">
            <span class="flex items-center gap-1.5 text-sm font-medium"><Icon :name="s.type === 'local' ? 'hard-drive' : 'archive'" class="size-4 text-muted-foreground" />{{ s.name }}</span>
            <span class="truncate text-xs text-muted-foreground">{{ storageHint(s) }}</span>
          </span>
        </label>
      </div>
      <p class="text-xs text-muted-foreground">Можно выбрать несколько — копия бекапа сохранится в каждое. Бекап удачный, если сохранился хотя бы в одно место.</p>
      <p v-for="m in [...(errors?.storage_ids ?? []), ...Object.entries(errors ?? {}).filter(([k]) => k.startsWith('storage_ids.')).flatMap(([, v]) => v)]" :key="m" class="text-sm text-destructive">{{ m }}</p>
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
