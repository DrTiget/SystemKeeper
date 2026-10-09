<script setup lang="ts">
import { ref } from 'vue'
import ChannelsTab from '@/components/alerts/ChannelsTab.vue'
import DeliveriesTab from '@/components/alerts/DeliveriesTab.vue'
import PushControl from '@/components/alerts/PushControl.vue'
import RulesTab from '@/components/alerts/RulesTab.vue'
import { btnClass } from '@/lib/format'

const tabs = [
  ['channels', 'Каналы'],
  ['rules', 'Правила'],
  ['deliveries', 'Журнал отправок'],
] as const
const tab = ref<(typeof tabs)[number][0]>('channels')
</script>

<template>
  <div class="flex flex-col gap-6 p-4">
    <header class="space-y-0.5">
      <h2 class="text-xl font-semibold tracking-tight">Уведомления</h2>
      <p class="text-sm text-muted-foreground">Куда и о чём сообщать: каналы доставки, правила по событиям и журнал отправок.</p>
    </header>
    <PushControl />
    <div class="flex flex-wrap gap-2">
      <button v-for="[k, label] in tabs" :key="k" :class="tab === k ? btnClass.default : btnClass.outline" @click="tab = k">{{ label }}</button>
    </div>
    <ChannelsTab v-if="tab === 'channels'" />
    <RulesTab v-else-if="tab === 'rules'" />
    <DeliveriesTab v-else />
  </div>
</template>
