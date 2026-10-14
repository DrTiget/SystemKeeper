<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/api/client'
import type { Project } from '@/api/projects'
import SelectMenu from '@/components/SelectMenu.vue'

/** Выбор проекта; null — без проекта. */
const model = defineModel<number | null>({ required: true })
defineProps<{ id?: string; invalid?: boolean; noneLabel?: string }>()

const projects = ref<Project[]>([])
onMounted(async () => {
  try {
    projects.value = (await api<{ data: Project[] }>('/projects?per_page=100')).data
  } catch {
    // список не загрузился — останется «без проекта»
  }
})

// SelectMenu не принимает null — «без проекта» обозначаем 0
const value = computed({ get: () => model.value ?? 0, set: (v: number) => (model.value = v || null) })
</script>

<template>
  <SelectMenu :id="id" v-model="value" :invalid="invalid" :options="[{ value: 0, label: noneLabel ?? 'Без проекта' }, ...projects.map((p) => ({ value: p.id, label: p.name }))]" />
</template>
