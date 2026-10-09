<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/api/client'
import type { BackupJob, Paginated } from '@/api/types'
import { btnClass } from '@/lib/format'
import BackupJobsList from '@/views/backups/BackupJobsList.vue'
import BackupsList from '@/views/backups/BackupsList.vue'

const tab = ref<'jobs' | 'backups'>('jobs')
const jobFilter = ref<number | null>(null)
const jobOptions = ref<BackupJob[]>([])

async function loadOptions() {
  try {
    jobOptions.value = (await api<Paginated<BackupJob>>('/backup-jobs?per_page=100')).data
  } catch {
    /* фильтр останется пустым */
  }
}
onMounted(loadOptions)

function showHistory(id: number) {
  jobFilter.value = id
  tab.value = 'backups'
}
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="space-y-0.5">
      <h2 class="text-xl font-semibold tracking-tight">Бекапы</h2>
      <p class="text-sm text-muted-foreground">Задания по расписанию и история выполненных бекапов.</p>
    </header>
    <div class="flex gap-2">
      <button :class="tab === 'jobs' ? btnClass.default : btnClass.outline" @click="tab = 'jobs'">Задания</button>
      <button :class="tab === 'backups' ? btnClass.default : btnClass.outline" @click="tab = 'backups'">Бекапы</button>
    </div>
    <BackupJobsList v-show="tab === 'jobs'" @history="showHistory" @changed="loadOptions" />
    <BackupsList v-if="tab === 'backups'" v-model:job-id="jobFilter" :jobs="jobOptions" />
  </div>
</template>
