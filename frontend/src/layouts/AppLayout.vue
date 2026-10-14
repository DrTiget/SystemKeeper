<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '@/components/Icon.vue'
import { useAuthStore } from '@/stores/auth'
import { useProjectsStore } from '@/stores/projects'

const auth = useAuthStore()
const projects = useProjectsStore()
const route = useRoute()
const router = useRouter()
const open = ref(false)

interface NavItem {
  to: string
  label: string
  icon: string
  admin?: boolean
}

// Меню: сначала проекты (серверы и базы — внутри них), затем хранение и администрирование
const navGroups: { title: string; admin?: boolean; items: NavItem[] }[] = [
  { title: 'Мониторинг', items: [{ to: '/monitors', label: 'Аптайм', icon: 'activity' }] },
  { title: 'Бекапы', items: [{ to: '/storages', label: 'Диски и облака', icon: 'archive' }] },
  { title: 'Администрирование', admin: true, items: [{ to: '/users', label: 'Пользователи', icon: 'users', admin: true }] },
]

const visibleGroups = computed(() =>
  navGroups
    .filter((g) => !g.admin || auth.user?.is_admin)
    .map((g) => ({ ...g, items: g.items.filter((i) => !i.admin || auth.user?.is_admin) })),
)

const initials = computed(() =>
  (auth.user?.name ?? '').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join(''),
)

onMounted(() => {
  auth.fetchUser()
  projects.load()
})

// Крошки задаёт страница; при уходе с неё сбрасываем
watch(() => route.fullPath, () => (projects.crumbs = []))

const link = 'flex h-8 items-center gap-2 rounded-md px-2 text-sm hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
const activeLink = 'bg-sidebar-accent font-medium text-sidebar-accent-foreground'

async function logout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="flex min-h-svh bg-sidebar">
    <div v-if="open" class="fixed inset-0 z-30 bg-black/80 md:hidden" @click="open = false" />
    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col bg-sidebar text-sidebar-foreground transition-transform md:sticky md:top-0 md:h-svh md:translate-x-0"
      :class="open ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="p-2">
        <RouterLink to="/" class="flex h-12 items-center gap-2 rounded-md p-2 hover:bg-sidebar-accent">
          <div class="flex size-8 shrink-0 items-center justify-center text-white">
            <Icon name="logo" class="size-5" />
          </div>
          <span class="ml-1 truncate text-lg leading-none font-extrabold tracking-tight text-white">System<span class="text-mint-400">Keeper</span></span>
        </RouterLink>
      </div>
      <nav class="flex flex-1 flex-col gap-4 overflow-auto p-2">
        <div class="flex flex-col gap-1">
          <div class="flex items-center justify-between px-2 text-xs font-medium text-sidebar-foreground/60">
            <RouterLink to="/projects" class="hover:text-sidebar-accent-foreground" @click="open = false">Проекты</RouterLink>
            <RouterLink to="/projects?new=1" class="rounded p-0.5 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground" title="Новый проект" @click="open = false"><Icon name="plus" class="size-3.5" /></RouterLink>
          </div>
          <template v-for="g in projects.grouped" :key="g.group?.id ?? 'none'">
            <!-- без группы: заголовок только если группы вообще есть -->
            <!-- группа принимает перетаскиваемые проекты; «Без группы» видна и пустой, пока идёт перетаскивание -->
            <button
              v-if="g.group || projects.groups.length"
              v-show="g.group || g.projects.length || projects.dragging !== null"
              class="mt-1 flex h-7 items-center gap-1 rounded-md px-2 text-xs font-medium text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
              :class="projects.isDropTarget(g.group) && 'bg-sidebar-accent text-sidebar-accent-foreground ring-1 ring-primary'"
              v-bind="projects.dropZone(g.group)"
              @click="projects.toggle(g.group)"
            >
              <Icon name="chevron-right" class="size-3.5 transition-transform" :class="!projects.isCollapsed(g.group) && 'rotate-90'" />
              <span class="truncate">{{ g.group?.name ?? 'Без группы' }}</span>
              <span class="ml-auto text-sidebar-foreground/50">{{ g.projects.length }}</span>
            </button>
            <template v-if="!projects.isCollapsed(g.group) || !(g.group || projects.groups.length)">
              <RouterLink
                v-for="p in g.projects"
                :key="p.id"
                :to="`/projects/${p.id}`"
                :class="[link, (g.group || projects.groups.length) && 'pl-6']"
                :active-class="activeLink"
                v-bind="projects.draggable(p.id)"
                @click="open = false"
              >
                <Icon name="folder" /><span class="truncate">{{ p.name }}</span>
              </RouterLink>
            </template>
          </template>
          <RouterLink v-if="projects.loaded && !projects.list.length" to="/projects?new=1" :class="link" class="text-sidebar-foreground/60" @click="open = false">
            <Icon name="plus" />Создать проект
          </RouterLink>
        </div>
        <div v-for="g in visibleGroups" :key="g.title" class="flex flex-col gap-1">
          <div class="px-2 text-xs font-medium text-sidebar-foreground/60">{{ g.title }}</div>
          <RouterLink
            v-for="i in g.items"
            :key="i.to"
            :to="i.to"
            class="flex h-8 items-center gap-2 rounded-md px-2 text-sm hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
            active-class="bg-sidebar-accent font-medium text-sidebar-accent-foreground"
            @click="open = false"
          >
            <Icon :name="i.icon" />{{ i.label }}
          </RouterLink>
        </div>
      </nav>
      <div class="p-2">
        <div class="flex h-12 items-center gap-1 rounded-md text-sm">
          <RouterLink
            to="/profile"
            class="flex min-w-0 flex-1 items-center gap-2 rounded-md p-2 hover:bg-sidebar-accent"
            active-class="bg-sidebar-accent"
            title="Профиль и настройки"
            @click="open = false"
          >
          <span class="flex size-8 items-center justify-center rounded-lg bg-sidebar-accent text-xs font-medium text-white">{{ initials }}</span>
          <span class="grid min-w-0 flex-1 text-left leading-tight">
            <span class="truncate font-medium text-white">{{ auth.user?.name }}</span>
            <span class="truncate text-xs">{{ auth.user?.email }}</span>
          </span>
          </RouterLink>
          <button class="inline-flex size-8 shrink-0 items-center justify-center rounded-md hover:bg-sidebar-accent hover:text-white" title="Выйти" @click="logout">
            <Icon name="logout" />
          </button>
        </div>
      </div>
    </aside>

    <div class="m-2 min-w-0 flex-1 rounded-xl bg-background shadow-sm md:ml-0">
      <header class="flex h-16 items-center gap-2 border-b border-sidebar-border/70 px-4">
        <button class="inline-flex size-7 items-center justify-center rounded-md hover:bg-accent hover:text-accent-foreground md:hidden" title="Меню" @click="open = true">
          <Icon name="panel" />
        </button>
        <nav class="flex min-w-0 items-center gap-1.5 text-sm text-muted-foreground">
          <span>SystemKeeper</span>
          <template v-if="projects.crumbs.length">
            <template v-for="(c, i) in projects.crumbs" :key="i">
              <span>/</span>
              <RouterLink v-if="c.to" :to="c.to" class="truncate hover:text-foreground">{{ c.label }}</RouterLink>
              <span v-else class="truncate text-foreground">{{ c.label }}</span>
            </template>
          </template>
          <template v-else><span>/</span><span class="text-foreground">{{ route.meta.title }}</span></template>
        </nav>
      </header>
      <!-- key: переход между проектами в меню пересоздаёт страницу -->
      <RouterView :key="route.path" />
    </div>
  </div>
</template>
