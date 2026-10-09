<script setup lang="ts">
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { btnClass } from '@/lib/format'

defineProps<{ title: string; text: string; confirmLabel?: string; loading?: boolean; error?: string }>()
const emit = defineEmits<{ confirm: []; close: [] }>()
</script>

<template>
  <Modal :title="title" :description="text" @close="emit('close')">
    <div v-if="error" role="alert" class="rounded-lg border bg-card px-4 py-3 text-sm text-destructive">{{ error }}</div>
    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
      <button type="button" :class="btnClass.outline" @click="emit('close')">Отмена</button>
      <button type="button" :class="btnClass.destructive" :disabled="loading" @click="emit('confirm')">
        <Icon v-if="loading" name="loader" class="animate-spin" />{{ confirmLabel ?? 'Удалить' }}
      </button>
    </div>
  </Modal>
</template>
