<script setup lang="ts">
import { ref } from 'vue'
import type { Server } from '@/api/servers'
import Modal from '@/components/Modal.vue'
import { btnClass } from '@/lib/ui'

const props = defineProps<{ server: Server }>()
const emit = defineEmits<{ close: []; check: [] }>()

const copied = ref(false)
const command = `mkdir -p ~/.ssh && chmod 700 ~/.ssh && echo '${props.server.public_key}' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys`

async function copy() {
  await navigator.clipboard.writeText(props.server.public_key ?? '')
  copied.value = true
  setTimeout(() => (copied.value = false), 1500)
}
</script>

<template>
  <Modal wide :title="`Открытый ключ: ${server.name}`" @close="emit('close')">
    <div class="grid gap-4 text-sm">
      <p class="text-muted-foreground">
        Добавьте ключ в <code class="font-mono text-xs">~/.ssh/authorized_keys</code> пользователя <b class="text-foreground">{{ server.username }}</b> на {{ server.host }}, затем проверьте подключение.
      </p>
      <pre class="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs break-all whitespace-pre-wrap">{{ server.public_key }}</pre>
      <div class="grid gap-1">
        <span class="text-muted-foreground">Или выполните на сервере:</span>
        <pre class="overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs break-all whitespace-pre-wrap">{{ command }}</pre>
      </div>
      <div class="flex justify-end gap-2">
        <button :class="btnClass.outline" @click="copy">{{ copied ? 'Скопировано' : 'Скопировать ключ' }}</button>
        <button :class="btnClass.primary" @click="emit('check')">Проверить подключение</button>
      </div>
    </div>
  </Modal>
</template>
