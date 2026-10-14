import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api/client'
import type { Project } from '@/api/projects'

export interface Crumb {
  label: string
  to?: string
}

/** Проекты для меню и хлебные крошки текущей страницы. */
export const useProjectsStore = defineStore('projects', () => {
  const list = ref<Project[]>([])
  const loaded = ref(false)
  /** Задаётся страницей; пусто — в шапке заголовок маршрута */
  const crumbs = ref<Crumb[]>([])

  async function load() {
    try {
      list.value = (await api<{ data: Project[] }>('/projects?per_page=100')).data
    } finally {
      loaded.value = true
    }
  }

  /** Крошки для страницы сервера или базы: «Проект / Название» или «Раздел / Название». */
  function setFor(project: { id: number; name: string } | null, section: Crumb, name: string) {
    crumbs.value = project ? [{ label: project.name, to: `/projects/${project.id}` }, { label: name }] : [section, { label: name }]
  }

  return { list, loaded, crumbs, load, setFor }
})
