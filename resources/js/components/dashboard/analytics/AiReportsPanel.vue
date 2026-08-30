<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Sparkles } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Card } from '../../ui/card';
import { Skeleton } from '../../ui/skeleton';

type Report = {
    id: number;
    period_type: 'weekly' | 'monthly';
    period_start: string;
    period_end: string;
    content: string;
    generated_by: string | null;
    created_at: string;
};

const props = defineProps<{ tenantSlug: string }>();
const locale = useLocaleStore();

const reports = ref<Report[]>([]);
const loading = ref(true);
const generating = ref<'weekly' | 'monthly' | null>(null);

async function load(): Promise<void> {
    if (! props.tenantSlug) return;
    loading.value = true;
    try {
        const data = await apiRequest<{ data: Report[] }>('/api/analytics/reports', { tenant: props.tenantSlug });
        reports.value = data.data;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

async function generate(type: 'weekly' | 'monthly'): Promise<void> {
    generating.value = type;
    try {
        await apiRequest('/api/analytics/reports/generate', { method: 'POST', body: { type }, tenant: props.tenantSlug });
        toast.success(locale.t('analytics.aiReports.generated'));
        await load();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        generating.value = null;
    }
}

function periodLabel(report: Report): string {
    return report.period_type === 'monthly' ? locale.t('analytics.aiReports.monthly') : locale.t('analytics.aiReports.weekly');
}

function formatRange(report: Report): string {
    const from = new Date(report.period_start).toLocaleDateString('ru-RU');
    const to = new Date(report.period_end).toLocaleDateString('ru-RU');

    return `${from} — ${to}`;
}

onMounted(load);
</script>

<template>
    <Card :title="locale.t('analytics.aiReports.title')" :subtitle="locale.t('analytics.aiReports.subtitle')">
        <div class="mb-4 flex flex-wrap gap-2">
            <Button size="sm" variant="outline" :disabled="generating !== null" @click="generate('weekly')">
                <Sparkles class="h-4 w-4" />{{ generating === 'weekly' ? locale.t('analytics.aiReports.generating') : locale.t('analytics.aiReports.generateWeekly') }}
            </Button>
            <Button size="sm" variant="outline" :disabled="generating !== null" @click="generate('monthly')">
                <Sparkles class="h-4 w-4" />{{ generating === 'monthly' ? locale.t('analytics.aiReports.generating') : locale.t('analytics.aiReports.generateMonthly') }}
            </Button>
        </div>

        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 2" :key="i" class="h-10 rounded-lg" />
        </div>
        <p v-else-if="! reports.length" class="pb-4 text-sm ui-subtle">{{ locale.t('analytics.aiReports.empty') }}</p>
        <div v-else class="space-y-3 pb-2">
            <details v-for="(report, i) in reports" :key="report.id" class="rounded-lg border border-border px-3 py-2" :open="i === 0">
                <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 text-sm font-medium ui-text">
                    <span>{{ periodLabel(report) }} · {{ formatRange(report) }}</span>
                    <span class="text-xs font-normal ui-subtle">{{ new Date(report.created_at).toLocaleDateString('ru-RU') }}</span>
                </summary>
                <p class="mt-2 whitespace-pre-line text-sm ui-subtle">{{ report.content }}</p>
            </details>
        </div>
    </Card>
</template>
