<script setup lang="ts">
import type { Server } from '@/api/servers'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { btnClass } from '@/lib/ui'

defineProps<{ server: Server; output: string | null; error: string | null; checking: boolean }>()
const emit = defineEmits<{ close: []; recheck: []; resetFingerprint: [] }>()
</script>

<template>
  <Modal wide :title="`Проверка: ${server.name}`" @close="emit('close')">
    <div class="grid gap-4 text-sm">
      <p v-if="checking" class="flex items-center gap-2 text-muted-foreground"><Icon name="loader" class="size-4 animate-spin" />Подключаемся…</p>
      <template v-else>
        <p v-if="!error" class="flex items-center gap-2"><span class="size-2 rounded-full bg-emerald-500" />Подключение работает</p>
        <p v-else class="text-destructive">{{ error }}</p>
        <pre v-if="output" class="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs whitespace-pre-wrap">{{ output }}</pre>
      </template>
      <div v-if="server.host_fingerprint" class="grid gap-1">
        <span class="text-muted-foreground">Отпечаток хоста</span>
        <code class="font-mono text-xs break-all">{{ server.host_fingerprint }}</code>
      </div>
      <p v-if="error === 'Отпечаток хоста изменился'" class="text-muted-foreground">
        Ключ сервера не совпал с запомненным. Если сервер переустанавливали, сбросьте отпечаток и проверьте снова; иначе это может быть подмена.
      </p>
      <div class="flex justify-end gap-2">
        <button v-if="server.host_fingerprint" :class="btnClass.outline" @click="emit('resetFingerprint')">Сбросить отпечаток</button>
        <button :class="btnClass.primary" :disabled="checking" @click="emit('recheck')">Проверить снова</button>
      </div>
    </div>
  </Modal>
</template>
