<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '@/components/Icon.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const open = ref(false)

interface NavItem {
  to: string
  label: string
  icon: string
  admin?: boolean
}

// Меню по группам; группа без доступных пунктов показывается только заголовком
const navGroups: { title: string; admin?: boolean; items: NavItem[] }[] = [
  { title: 'Администрирование', admin: true, items: [{ to: '/users', label: 'Пользователи', icon: 'users', admin: true }] },
  {
    title: 'Бекапы',
    items: [
      { to: '/servers', label: 'Сервера', icon: 'server' },
      { to: '/databases', label: 'Базы данных', icon: 'database' },
    ],
  },
  { title: 'Хранение бекапов', items: [{ to: '/storages', label: 'Хранилища', icon: 'archive' }] },
]

const visibleGroups = computed(() =>
  navGroups
    .filter((g) => !g.admin || auth.user?.is_admin)
    .map((g) => ({ ...g, items: g.items.filter((i) => !i.admin || auth.user?.is_admin) })),
)

const initials = computed(() =>
  (auth.user?.name ?? '').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join(''),
)

onMounted(() => auth.fetchUser())

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
        <nav class="flex items-center gap-1.5 text-sm text-muted-foreground">
          <span>SystemKeeper</span><span>/</span><span class="text-foreground">{{ route.meta.title }}</span>
        </nav>
      </header>
      <RouterView />
    </div>
  </div>
</template>
