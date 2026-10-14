export interface Project {
  id: number
  group_id: number | null
  name: string
  description: string | null
  servers_count?: number
  databases_count?: number
  created_at: string
}

export type ProjectRef = Pick<Project, 'id' | 'name'>

export interface ProjectGroup {
  id: number
  name: string
  projects_count?: number
}
