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
        { path: '', redirect: '/projects' },
        { path: 'projects', name: 'projects', component: () => import('@/views/ProjectsView.vue'), meta: { title: 'Проекты' } },
        { path: 'projects/:id(\\d+)', name: 'project', component: () => import('@/views/ProjectDetailView.vue'), meta: { title: 'Проекты' } },
        { path: 'servers', name: 'servers', component: () => import('@/views/ServersView.vue'), meta: { title: 'Сервера' } },
        { path: 'servers/:id(\\d+)', name: 'server', component: () => import('@/views/ServerDetailView.vue'), meta: { title: 'Сервера' } },
        { path: 'databases', name: 'databases', component: () => import('@/views/DatabasesView.vue'), meta: { title: 'Базы данных' } },
        { path: 'databases/:id(\\d+)', name: 'database', component: () => import('@/views/DatabaseDetailView.vue'), meta: { title: 'Базы данных' } },
        { path: 'backups', name: 'backups', component: () => import('@/views/LatestBackupsView.vue'), meta: { title: 'Последние бекапы' } },
        { path: 'backups/:id(\\d+)', name: 'backup', component: () => import('@/views/BackupDetailView.vue'), meta: { title: 'Бекап' } },
        { path: 'monitors', name: 'monitors', component: () => import('@/views/MonitorsView.vue'), meta: { title: 'Аптайм' } },
        { path: 'monitors/new', name: 'monitor-new', component: () => import('@/views/MonitorFormView.vue'), meta: { title: 'Аптайм' } },
        { path: 'monitors/:id(\\d+)', name: 'monitor', component: () => import('@/views/MonitorDetailView.vue'), meta: { title: 'Аптайм' } },
        { path: 'monitors/:id(\\d+)/edit', name: 'monitor-edit', component: () => import('@/views/MonitorFormView.vue'), meta: { title: 'Аптайм' } },
        { path: 'metrics', name: 'metrics', component: () => import('@/views/MetricsView.vue'), meta: { title: 'Серверы' } },
        { path: 'servers/:id(\\d+)/metrics', name: 'server-metrics', component: () => import('@/views/ServerMetricsView.vue'), meta: { title: 'Серверы' } },
        { path: 'alerts', name: 'alerts', component: () => import('@/views/AlertsView.vue'), meta: { title: 'Алерты' } },
        { path: 'alerts/rules', name: 'alert-rules', component: () => import('@/views/AlertRulesView.vue'), meta: { title: 'Алерты' } },
        { path: 'alerts/rules/new', name: 'alert-rule-new', component: () => import('@/views/AlertRuleFormView.vue'), meta: { title: 'Алерты' } },
        { path: 'alerts/rules/:id(\\d+)', name: 'alert-rule-edit', component: () => import('@/views/AlertRuleFormView.vue'), meta: { title: 'Алерты' } },
        { path: 'alerts/channels', name: 'alert-channels', component: () => import('@/views/AlertChannelsView.vue'), meta: { title: 'Алерты' } },
        { path: 'storages', name: 'storages', component: () => import('@/views/StoragesView.vue'), meta: { title: 'Диски и облака' } },
        { path: 'users', name: 'users', component: () => import('@/views/UsersView.vue'), meta: { title: 'Пользователи', admin: true } },
        { path: 'applications', name: 'applications', component: () => import('@/views/ApplicationsView.vue'), meta: { title: 'Приложения', admin: true } },
        { path: 'api-docs', name: 'api-docs', component: () => import('@/views/ApiDocsView.vue'), meta: { title: 'Документация API' } },
        { path: 'audit', name: 'audit', component: () => import('@/views/AuditLogView.vue'), meta: { title: 'Журнал', admin: true } },
        { path: 'settings/notifications', name: 'settings-notifications', component: () => import('@/views/NotificationSettingsView.vue'), meta: { title: 'Уведомления', admin: true } },
        { path: 'profile', name: 'profile', component: () => import('@/views/ProfileView.vue'), meta: { title: 'Профиль' } },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (to.meta.auth && !auth.token) return { name: 'login' }
  if (to.meta.guest && auth.token) return { name: 'projects' }
  // Разделы администратора: API всё равно ответит 403, а здесь просто не пускаем на страницу
  if (to.matched.some((r) => r.meta.admin)) {
    if (!auth.user) await auth.fetchUser()
    if (!auth.user?.is_admin) return { name: 'projects' }
  }
})

// 401 от API → разлогин и переход на вход
setUnauthorizedHandler(() => {
  useAuthStore().clear()
  router.push({ name: 'login' })
})

export default router
