export interface Project {
  id: number
  name: string
  description: string | null
  servers_count?: number
  databases_count?: number
  created_at: string
}

export type ProjectRef = Pick<Project, 'id' | 'name'>
