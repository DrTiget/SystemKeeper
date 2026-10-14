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
        { path: '', name: 'dashboard', component: () => import('@/views/StubView.vue'), meta: { title: 'Дашборд' } },
        { path: 'servers', name: 'servers', component: () => import('@/views/ServersView.vue'), meta: { title: 'Сервера' } },
        { path: 'servers/:id(\\d+)', name: 'server', component: () => import('@/views/ServerDetailView.vue'), meta: { title: 'Сервера' } },
        { path: 'databases', name: 'databases', component: () => import('@/views/DatabasesView.vue'), meta: { title: 'Базы данных' } },
        { path: 'databases/:id(\\d+)', name: 'database', component: () => import('@/views/DatabaseDetailView.vue'), meta: { title: 'Базы данных' } },
        { path: 'storages', name: 'storages', component: () => import('@/views/StoragesView.vue'), meta: { title: 'Хранилища' } },
        { path: 'users', name: 'users', component: () => import('@/views/UsersView.vue'), meta: { title: 'Пользователи', admin: true } },
        { path: 'profile', name: 'profile', component: () => import('@/views/ProfileView.vue'), meta: { title: 'Профиль' } },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (to.meta.auth && !auth.token) return { name: 'login' }
  if (to.meta.guest && auth.token) return { name: 'dashboard' }
  // Разделы администратора: API всё равно ответит 403, а здесь просто не пускаем на страницу
  if (to.matched.some((r) => r.meta.admin)) {
    if (!auth.user) await auth.fetchUser()
    if (!auth.user?.is_admin) return { name: 'dashboard' }
  }
})

// 401 от API → разлогин и переход на вход
setUnauthorizedHandler(() => {
  useAuthStore().clear()
  router.push({ name: 'login' })
})

export default router
