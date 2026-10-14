import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { api } from '@/api/client'
import type { Project, ProjectGroup } from '@/api/projects'

export interface Crumb {
  label: string
  to?: string
}

/** Проекты для меню и хлебные крошки текущей страницы. */
export const useProjectsStore = defineStore('projects', () => {
  const list = ref<Project[]>([])
  const groups = ref<ProjectGroup[]>([])

  /** Проекты по группам (в порядке групп), в конце — без группы */
  const grouped = computed(() => [
    ...groups.value.map((g) => ({ group: g as ProjectGroup | null, projects: list.value.filter((p) => p.group_id === g.id) })),
    { group: null as ProjectGroup | null, projects: list.value.filter((p) => !p.group_id || !groups.value.some((g) => g.id === p.group_id)) },
  ])

  // Свёрнутые группы меню — в localStorage, переживают перезагрузку; ключ группы без проекта — 'none'
  const COLLAPSED_KEY = 'systemkeeper_nav_collapsed'
  const collapsed = ref<string[]>(JSON.parse(localStorage.getItem(COLLAPSED_KEY) ?? '[]'))
  watch(collapsed, (v) => localStorage.setItem(COLLAPSED_KEY, JSON.stringify(v)), { deep: true })
  const groupKey = (g: ProjectGroup | null) => (g ? String(g.id) : 'none')
  const isCollapsed = (g: ProjectGroup | null) => collapsed.value.includes(groupKey(g))
  function toggle(g: ProjectGroup | null) {
    const k = groupKey(g)
    collapsed.value = isCollapsed(g) ? collapsed.value.filter((x) => x !== k) : [...collapsed.value, k]
  }
  const loaded = ref(false)
  /** Задаётся страницей; пусто — в шапке заголовок маршрута */
  const crumbs = ref<Crumb[]>([])

  async function load() {
    try {
      const [p, g] = await Promise.all([api<{ data: Project[] }>('/projects?per_page=100'), api<{ data: ProjectGroup[] }>('/project-groups')])
      list.value = p.data
      groups.value = g.data
    } finally {
      loaded.value = true
    }
  }

  /** Крошки для страницы сервера или базы: «Проект / Название» или «Раздел / Название». */
  function setFor(project: { id: number; name: string } | null, section: Crumb, name: string) {
    crumbs.value = project ? [{ label: project.name, to: `/projects/${project.id}` }, { label: name }] : [section, { label: name }]
  }

  // Перенос проекта в группу перетаскиванием (меню и страница проектов)
  const dragging = ref<number | null>(null)
  const dropTarget = ref<string | null>(null)

  async function moveProject(projectId: number, group: ProjectGroup | null) {
    const project = list.value.find((p) => p.id === projectId)
    const groupId = group?.id ?? null
    if (!project || project.group_id === groupId) return
    project.group_id = groupId // сразу в интерфейсе; при ошибке — перечитаем
    try {
      await api(`/projects/${projectId}`, { method: 'PATCH', body: { group_id: groupId } })
    } finally {
      await load()
    }
  }

  /** Обработчики для элемента-группы, принимающего проекты. */
  function dropZone(group: ProjectGroup | null): Record<string, unknown> {
    const key = groupKey(group)
    return {
      onDragover: (e: DragEvent) => {
        if (dragging.value === null) return
        e.preventDefault()
        dropTarget.value = key
      },
      onDragleave: () => {
        if (dropTarget.value === key) dropTarget.value = null
      },
      onDrop: (e: DragEvent) => {
        e.preventDefault()
        const id = dragging.value
        dragging.value = dropTarget.value = null
        if (id !== null) moveProject(id, group)
      },
    }
  }

  /** Обработчики для перетаскиваемого проекта. */
  function draggable(projectId: number): Record<string, unknown> {
    return {
      draggable: 'true',
      onDragstart: (e: DragEvent) => {
        dragging.value = projectId
        e.dataTransfer?.setData('text/plain', String(projectId))
        if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move'
      },
      onDragend: () => {
        dragging.value = dropTarget.value = null
      },
    }
  }

  const isDropTarget = (g: ProjectGroup | null) => dropTarget.value === groupKey(g)

  return { dragging, dropZone, draggable, isDropTarget, moveProject, list, groups, grouped, loaded, crumbs, load, setFor, isCollapsed, toggle }
})
