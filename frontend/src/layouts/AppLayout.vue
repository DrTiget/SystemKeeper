<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '@/components/Icon.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const open = ref(false)

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
      class="fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col bg-sidebar text-sidebar-foreground transition-transform md:static md:translate-x-0"
      :class="open ? 'translate-x-0' : '-translate-x-full'"
    >
      <div class="p-2">
        <RouterLink to="/" class="flex h-12 items-center gap-2 rounded-md p-2 hover:bg-sidebar-accent">
          <div class="flex aspect-square size-8 shrink-0 items-center justify-center rounded-lg bg-ink-800/60 text-white">
            <Icon name="logo" class="size-5" />
          </div>
          <span class="ml-1 truncate text-lg leading-none font-extrabold tracking-tight text-white">System<span class="text-mint-400">Keeper</span></span>
        </RouterLink>
      </div>
      <div class="flex-1" />
      <div class="p-2">
        <div class="flex h-12 items-center gap-2 rounded-md p-2 text-sm">
          <span class="flex size-8 items-center justify-center rounded-lg bg-sidebar-accent text-xs font-medium text-white">{{ initials }}</span>
          <span class="grid min-w-0 flex-1 text-left leading-tight">
            <span class="truncate font-medium text-white">{{ auth.user?.name }}</span>
            <span class="truncate text-xs">{{ auth.user?.email }}</span>
          </span>
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
