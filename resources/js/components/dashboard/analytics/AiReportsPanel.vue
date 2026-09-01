<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { CalendarDays, CalendarRange, Sparkles } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '../../ui/accordion';
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
            <Skeleton v-for="i in 2" :key="i" class="h-14 rounded-xl" />
        </div>
        <p v-else-if="! reports.length" class="pb-4 text-sm ui-subtle">{{ locale.t('analytics.aiReports.empty') }}</p>
        <Accordion
            v-else
            type="single"
            collapsible
            :default-value="reports[0] ? String(reports[0].id) : undefined"
            class="space-y-2 pb-2"
        >
            <AccordionItem
                v-for="report in reports"
                :key="report.id"
                :value="String(report.id)"
                class="rounded-xl border border-border bg-card transition-colors data-[state=open]:border-primary/40 data-[state=open]:bg-primary/[0.03] hover:border-primary/30"
            >
                <AccordionTrigger class="px-4 py-3 hover:no-underline">
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <span
                            class="grid h-9 w-9 shrink-0 place-items-center rounded-lg"
                            :class="report.period_type === 'monthly' ? 'bg-violet-500/10 text-violet-600 dark:text-violet-400' : 'bg-primary/10 text-primary'"
                        >
                            <component :is="report.period_type === 'monthly' ? CalendarRange : CalendarDays" class="h-4 w-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm font-semibold ui-text">
                                <span>{{ periodLabel(report) }}</span>
                                <span class="font-mono text-xs font-normal tabular-nums ui-subtle">{{ formatRange(report) }}</span>
                            </p>
                            <p class="text-xs ui-subtle">{{ locale.t('analytics.aiReports.generated') }} · {{ new Date(report.created_at).toLocaleDateString('ru-RU') }}</p>
                        </div>
                    </div>
                </AccordionTrigger>
                <AccordionContent class="px-4">
                    <p class="whitespace-pre-line rounded-lg bg-muted/50 p-3 text-sm leading-6 ui-subtle">{{ report.content }}</p>
                </AccordionContent>
            </AccordionItem>
        </Accordion>
    </Card>
</template>
