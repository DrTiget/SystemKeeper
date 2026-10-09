<script setup lang="ts">
import { ref } from 'vue'
import Icon from '@/components/Icon.vue'
import { btnClass } from '@/lib/format'

const props = defineProps<{ text: string; label?: string }>()
const done = ref(false)
const failed = ref(false)

async function copy() {
  failed.value = false
  try {
    if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(props.text)
    else {
      // http без secure context — запасной путь через временное поле
      const ta = document.createElement('textarea')
      ta.value = props.text
      ta.style.position = 'fixed'
      ta.style.opacity = '0'
      document.body.appendChild(ta)
      ta.select()
      const ok = document.execCommand('copy')
      ta.remove()
      if (!ok) throw new Error('copy')
    }
    done.value = true
    setTimeout(() => (done.value = false), 2000)
  } catch {
    failed.value = true
  }
}
</script>

<template>
  <button type="button" :class="btnClass.outlineSm" @click="copy">
    <Icon :name="done ? 'check' : 'copy'" />{{ failed ? 'Не удалось' : done ? 'Скопировано' : (label ?? 'Копировать') }}
  </button>
</template>
