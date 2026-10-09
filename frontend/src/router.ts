import { createRouter, createWebHistory } from 'vue-router'
import { setUnauthorizedHandler } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guest: true } },
    {
      path: '/',
      component: () => import('@/layouts/AppLayout.vue'),
      meta: { auth: true },
      children: [
        { path: '', name: 'dashboard', component: () => import('@/views/DashboardView.vue'), meta: { title: 'Дашборд' } },
        { path: 'servers', name: 'servers', component: () => import('@/views/ServersView.vue'), meta: { title: 'Серверы' } },
        { path: 'ssh-keys', name: 'ssh-keys', component: () => import('@/views/SshKeysView.vue'), meta: { title: 'SSH-ключи' } },
        { path: 'storage-targets', name: 'storage-targets', component: () => import('@/views/StorageTargetsView.vue'), meta: { title: 'Хранилища' } },
        { path: 'backups', name: 'backups', component: () => import('@/views/BackupsView.vue'), meta: { title: 'Бекапы' } },
        { path: 'monitoring', name: 'monitoring', component: () => import('@/views/MonitoringView.vue'), meta: { title: 'Мониторинг' } },
        { path: 'monitoring/:id(\\d+)', name: 'monitor', component: () => import('@/views/MonitorDetailView.vue'), meta: { title: 'Мониторинг' } },
        { path: 'settings', name: 'settings', component: () => import('@/views/StubView.vue'), meta: { title: 'Настройки' } },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach((to) => {
  const auth = useAuthStore()
  if (to.meta.auth && !auth.token) return { name: 'login' }
  if (to.meta.guest && auth.token) return { name: 'dashboard' }
})

// 401 от API → разлогин и переход на вход
setUnauthorizedHandler(() => {
  useAuthStore().clear()
  router.push({ name: 'login' })
})

export default router
