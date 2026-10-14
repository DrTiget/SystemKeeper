<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client'
import type { Project } from '@/api/projects'
import Modal from '@/components/Modal.vue'
import SelectMenu from '@/components/SelectMenu.vue'
import { useProjectsStore } from '@/stores/projects'
import { btnClass, inputClass } from '@/lib/ui'

const props = defineProps<{ project: Project | null; groupId?: number | null }>()
const emit = defineEmits<{ close: []; saved: [project: Project] }>()

const store = useProjectsStore()
const form = reactive({ name: props.project?.name ?? '', description: props.project?.description ?? '', group_id: props.project ? props.project.group_id : (props.groupId ?? null) })
// SelectMenu не принимает null — «без группы» обозначаем 0
const groupValue = computed({ get: () => form.group_id ?? 0, set: (v: number) => (form.group_id = v || null) })
const errors = ref<Record<string, string[]>>({})
const formError = ref('')
const saving = ref(false)

async function save() {
  saving.value = true
  errors.value = {}
  formError.value = ''
  const body = { name: form.name, description: form.description || null, group_id: form.group_id }
  try {
    const res = props.project
      ? await api<{ data: Project }>(`/projects/${props.project.id}`, { method: 'PATCH', body })
      : await api<{ data: Project }>('/projects', { method: 'POST', body })
    emit('saved', res.data)
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) errors.value = e.errors
    else formError.value = e instanceof ApiError ? e.message : 'Не удалось сохранить'
  } finally {
    saving.value = false
  }
}

const label = 'text-sm leading-none font-medium'
</script>

<template>
  <Modal :title="project ? 'Изменить проект' : 'Новый проект'" @close="emit('close')">
    <form class="grid gap-4" @submit.prevent="save">
      <div class="grid gap-2">
        <label for="p-name" :class="label">Название</label>
        <input id="p-name" v-model="form.name" required placeholder="Интернет-магазин" :class="inputClass" :aria-invalid="!!errors.name" />
        <p v-for="m in errors.name" :key="m" class="text-sm text-destructive">{{ m }}</p>
      </div>
      <div class="grid gap-2">
        <label for="p-description" :class="label">Описание</label>
        <input id="p-description" v-model="form.description" placeholder="Необязательно" :class="inputClass" />
      </div>
      <div v-if="store.groups.length" class="grid gap-2">
        <label for="p-group" :class="label">Группа</label>
        <SelectMenu id="p-group" v-model="groupValue" :options="[{ value: 0, label: 'Без группы' }, ...store.groups.map((g) => ({ value: g.id, label: g.name }))]" />
      </div>
      <p v-if="formError" class="text-sm text-destructive">{{ formError }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" :class="btnClass.outline" @click="emit('close')">Отмена</button>
        <button type="submit" :disabled="saving" :class="btnClass.primary">{{ project ? 'Сохранить' : 'Создать' }}</button>
      </div>
    </form>
  </Modal>
</template>
