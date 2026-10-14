<script setup lang="ts">
import Icon from '@/components/Icon.vue'
import { btnClass } from '@/lib/ui'

/** Панель сохранения внизу формы: прилипает к низу экрана, пока есть несохранённые изменения. */
defineProps<{ dirty: boolean; saving: boolean; saved?: boolean; error?: string; disabledReason?: string }>()
defineEmits<{ reset: [] }>()
</script>

<template>
  <div
    class="sticky bottom-0 z-10 -mx-6 -mb-6 flex flex-wrap items-center gap-3 rounded-b-xl border-t px-6 py-3 transition-colors"
    :class="dirty ? 'bg-card shadow-[0_-4px_12px_-6px_rgb(0_0_0/0.2)]' : 'bg-card'"
  >
    <span v-if="error" class="min-w-0 flex-1 text-sm whitespace-pre-wrap text-destructive">{{ error }}</span>
    <span v-else-if="dirty && disabledReason" class="flex-1 text-sm text-muted-foreground">{{ disabledReason }}</span>
    <span v-else-if="dirty" class="flex flex-1 items-center gap-2 text-sm"><span class="size-2 rounded-full bg-amber-500" />Есть несохранённые изменения</span>
    <span v-else-if="saved" class="flex flex-1 items-center gap-1.5 text-sm text-muted-foreground"><Icon name="check" class="size-4 text-primary" />Сохранено</span>
    <span v-else class="flex-1" />
    <button v-if="dirty" type="button" :class="btnClass.outline" :disabled="saving" @click="$emit('reset')">Отменить</button>
    <button type="submit" :class="btnClass.primary" :disabled="!dirty || saving || !!disabledReason">
      <Icon v-if="saving" name="loader" class="animate-spin" />Сохранить
    </button>
  </div>
</template>
