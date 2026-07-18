<script setup lang="ts">
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import type { Lead } from '../../stores/crmDashboard';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Badge } from '../ui/badge';
import { Card } from '../ui/card';
import { ToggleGroup, ToggleGroupItem } from '../ui/toggle-group';

defineProps<{ selectedId?: number | null }>();
defineEmits<{ select: [lead: Lead] }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { filteredLeads, leadStatus, busy } = storeToRefs(store);
const statuses = computed(() => ['all', 'new', 'qualified', 'won', 'lost'].map((status) => ({ value: status, label: locale.t(`leads.statuses.${status}`) })));
</script>

<template>
    <Card :title="locale.t('leads.title')" :subtitle="locale.t('leads.subtitle')">
        <template #actions>
            <ToggleGroup v-model="leadStatus" type="single" variant="outline" size="sm" class="flex-wrap">
                <ToggleGroupItem v-for="status in statuses" :key="status.value" :value="status.value">{{ status.label }}</ToggleGroupItem>
            </ToggleGroup>
        </template>
        <div class="divide-y divide-white/10">
            <button v-for="lead in filteredLeads" :key="lead.id" class="block w-full py-4 text-left first:pt-0 last:pb-0" @click="$emit('select', lead)">
                <div :class="['grid gap-3 rounded-md border p-3 sm:grid-cols-[1fr_auto] sm:items-center', selectedId === lead.id ? 'border-emerald-300/40 bg-emerald-300/10' : 'border-transparent hover:bg-white/[0.04]']">
                    <div>
                        <p class="font-medium text-white">{{ lead.title }}</p>
                        <p class="mt-1 text-sm text-zinc-400">{{ lead.source ?? locale.t('common.manual') }} - {{ locale.t('common.aiScore') }} {{ lead.score }}</p>
                        <p v-if="lead.ai_summary" class="mt-2 max-w-2xl text-sm text-zinc-500">{{ lead.ai_summary }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                        <Badge :tone="lead.status === 'qualified' ? 'green' : 'blue'">{{ locale.t(`leads.statuses.${lead.status}`) }}</Badge>
                        <button class="rounded-sm border border-white/10 px-2 py-1 text-xs text-zinc-300 hover:bg-white/10 disabled:opacity-50" :disabled="busy" @click.stop="store.updateLeadStatus(lead.id, 'qualified')">{{ locale.t('crm.qualify') }}</button>
                        <button class="rounded-sm border border-white/10 px-2 py-1 text-xs text-zinc-300 hover:bg-white/10 disabled:opacity-50" :disabled="busy" @click.stop="store.updateLeadStatus(lead.id, 'won')">{{ locale.t('crm.win') }}</button>
                    </div>
                </div>
            </button>
        </div>
    </Card>
</template>