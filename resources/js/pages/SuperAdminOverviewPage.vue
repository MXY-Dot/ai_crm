<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { VisAxis, VisGroupedBar, VisXYContainer } from '@unovis/vue';
import { AlertTriangle, Building2, CircleDollarSign, Download, Info, TrendingUp } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { planById } from '@/lib/plans';
import KpiCard from '@/components/dashboard/KpiCard.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { type ChartConfig, ChartContainer, ChartCrosshair, ChartTooltip, ChartTooltipContent, componentToString } from '@/components/ui/chart';

defineOptions({ layout: SuperAdminLayout });

type Overview = {
    kpis: { total_companies: number; active_companies: number; mrr: number; arr: number };
    growth: { label: string; count: number }[];
    message_volume: { ai: number; operator: number; customer: number };
    channels: { provider: string; total: number; active: number }[];
    alerts: { severity: 'warning' | 'error' | 'info'; title: string; message: string }[];
    recent_signups: { id: number; name: string; plan: string; status: string; created_at: string }[];
};

const data = ref<Overview | null>(null);
const loading = ref(true);

type GrowthPoint = { month: string; count: number };
const growthData = computed<GrowthPoint[]>(() => data.value?.growth.map((g) => ({ month: g.label, count: g.count })) ?? []);
const growthConfig: ChartConfig = {
    count: { label: 'Новых компаний', color: 'var(--primary)' },
};

type VolumePoint = { period: string; ai: number; operator: number; customer: number };
const volumeData = computed<VolumePoint[]>(() => {
    if (! data.value) return [];
    const { ai, operator, customer } = data.value.message_volume;
    return [{ period: 'Последние 30 дней', ai, operator, customer }];
});
const volumeConfig: ChartConfig = {
    ai: { label: 'AI', color: 'var(--primary)' },
    operator: { label: 'Операторы', color: 'var(--accent-foreground)' },
    customer: { label: 'Клиенты', color: 'var(--muted-foreground)' },
};

const severityStyle: Record<string, string> = {
    error: 'border-destructive/40 bg-destructive/5',
    warning: 'border-amber-500/40 bg-amber-500/5',
    info: 'border-sky-500/40 bg-sky-500/5',
};
const severityIcon: Record<string, string> = { error: 'text-destructive', warning: 'text-amber-600', info: 'text-sky-600' };

const providerLabels: Record<string, string> = { telegram: 'Telegram', whatsapp: 'WhatsApp', website: 'Виджет сайта', chatwoot: 'Единый инбокс', instagram: 'Instagram' };

function formatMoney(value: number): string {
    return new Intl.NumberFormat('ru-RU').format(value) + ' $';
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short' }).format(new Date(value));
}

function csvEscape(value: string): string {
    return `"${value.replace(/"/g, '""')}"`;
}

function exportReport(): void {
    if (! data.value) return;
    const d = data.value;
    const rows: string[][] = [
        ['Обзор платформы WERO', new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date())],
        [],
        ['Ключевые метрики'],
        ['Всего компаний', String(d.kpis.total_companies)],
        ['Активные компании', String(d.kpis.active_companies)],
        ['MRR', formatMoney(d.kpis.mrr)],
        ['ARR (прогноз)', formatMoney(d.kpis.arr)],
        [],
        ['Рост компаний по месяцам'],
        ['Месяц', 'Новых компаний'],
        ...d.growth.map((g) => [g.label, String(g.count)]),
        [],
        ['Сообщения за 30 дней'],
        ['AI', String(d.message_volume.ai)],
        ['Операторы', String(d.message_volume.operator)],
        ['Клиенты', String(d.message_volume.customer)],
        [],
        ['Каналы связи'],
        ['Провайдер', 'Активны', 'Всего'],
        ...d.channels.map((c) => [providerLabels[c.provider] ?? c.provider, String(c.active), String(c.total)]),
        [],
        ['Требует внимания'],
        ...(d.alerts.length ? d.alerts.map((a) => [a.title, a.message]) : [['Нет активных предупреждений', '']]),
        [],
        ['Последние регистрации'],
        ['Компания', 'Тариф', 'Статус', 'Дата'],
        ...d.recent_signups.map((s) => [s.name, planById(s.plan).name, s.status, formatDate(s.created_at)]),
    ];

    const csv = rows.map((row) => row.map(csvEscape).join(',')).join('\n');
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `wero-platform-overview-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
}

async function load(): Promise<void> {
    loading.value = true;
    try {
        data.value = await apiRequest<Overview>('/api/admin/overview');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить обзор платформы');
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-display text-xl font-bold ui-text">Обзор платформы</h2>
            <p class="mt-1 text-sm ui-subtle">Ключевые метрики и состояние всех компаний на платформе.</p>
        </div>
        <Button variant="outline" size="sm" :disabled="loading || !data" @click="exportReport"><Download class="h-4 w-4" />Экспорт отчёта</Button>
    </div>

    <div v-if="loading" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Skeleton v-for="i in 4" :key="i" class="h-28 rounded-xl" />
    </div>

    <template v-else-if="data">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <KpiCard label="Всего компаний" :value="data.kpis.total_companies">
                <template #icon><Building2 class="h-4 w-4 ui-subtle" /></template>
            </KpiCard>
            <KpiCard
                label="Активные"
                :value="data.kpis.active_companies"
                :hint="`${data.kpis.total_companies ? Math.round((data.kpis.active_companies / data.kpis.total_companies) * 100) : 0}% от всех компаний`"
            >
                <template #icon><TrendingUp class="h-4 w-4 text-primary" /></template>
            </KpiCard>
            <KpiCard label="MRR" :value="formatMoney(data.kpis.mrr)" hint="По активным тарифам">
                <template #icon><CircleDollarSign class="h-4 w-4 ui-subtle" /></template>
            </KpiCard>
            <KpiCard label="ARR (прогноз)" :value="formatMoney(data.kpis.arr)" hint="MRR × 12">
                <template #icon><CircleDollarSign class="h-4 w-4 ui-subtle" /></template>
            </KpiCard>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-xl border border-border bg-card p-5 lg:col-span-2">
                <h3 class="font-display text-base font-semibold ui-text">Рост компаний</h3>
                <p class="text-xs ui-subtle">Регистрации за последние 6 месяцев</p>
                <ChartContainer :config="growthConfig" class="mt-4 h-56 w-full">
                    <VisXYContainer :data="growthData">
                        <VisGroupedBar
                            :x="(d: GrowthPoint, i: number) => i"
                            :y="[(d: GrowthPoint) => d.count]"
                            :color="[growthConfig.count.color]"
                            :rounded-corners="4"
                        />
                        <VisAxis
                            type="x"
                            :x="(d: GrowthPoint, i: number) => i"
                            :tick-line="false"
                            :domain-line="false"
                            :grid-line="false"
                            :tick-format="(i: number) => growthData[i]?.month ?? ''"
                        />
                        <VisAxis type="y" :tick-format="() => ''" :tick-line="false" :domain-line="false" :grid-line="true" />
                        <ChartTooltip />
                        <ChartCrosshair :template="componentToString(growthConfig, ChartTooltipContent)" :color="[growthConfig.count.color]" />
                    </VisXYContainer>
                </ChartContainer>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <h3 class="font-display text-base font-semibold ui-text">Сообщения за 30 дней</h3>
                <p class="text-xs ui-subtle">Кто обрабатывает диалоги на платформе</p>
                <ChartContainer :config="volumeConfig" class="mt-4 h-56 w-full">
                    <VisXYContainer :data="volumeData">
                        <VisGroupedBar
                            :x="(d: VolumePoint, i: number) => i"
                            :y="[(d: VolumePoint) => d.ai, (d: VolumePoint) => d.operator, (d: VolumePoint) => d.customer]"
                            :color="[volumeConfig.ai.color, volumeConfig.operator.color, volumeConfig.customer.color]"
                            :rounded-corners="4"
                        />
                        <VisAxis type="x" :x="() => 0" :tick-format="() => ''" :tick-line="false" :domain-line="false" :grid-line="false" />
                        <VisAxis type="y" :tick-format="() => ''" :tick-line="false" :domain-line="false" :grid-line="true" />
                        <ChartTooltip />
                        <ChartCrosshair
                            :template="componentToString(volumeConfig, ChartTooltipContent)"
                            :color="[volumeConfig.ai.color, volumeConfig.operator.color, volumeConfig.customer.color]"
                        />
                    </VisXYContainer>
                </ChartContainer>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-xl border border-border bg-card p-5">
                <h3 class="mb-4 font-display text-base font-semibold ui-text">Каналы связи</h3>
                <div v-if="data.channels.length" class="space-y-3">
                    <div v-for="channel in data.channels" :key="channel.provider" class="flex items-center justify-between text-sm">
                        <span class="ui-text">{{ providerLabels[channel.provider] ?? channel.provider }}</span>
                        <span class="font-mono text-xs ui-subtle">{{ channel.active }} / {{ channel.total }} активны</span>
                    </div>
                </div>
                <p v-else class="text-sm ui-subtle">Каналы ещё не подключены</p>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <h3 class="mb-4 flex items-center gap-2 font-display text-base font-semibold ui-text"><AlertTriangle class="h-4 w-4 text-amber-600" />Требует внимания</h3>
                <div v-if="data.alerts.length" class="space-y-2">
                    <div v-for="(alert, index) in data.alerts" :key="index" class="rounded-lg border p-3" :class="severityStyle[alert.severity]">
                        <div class="flex items-center gap-1.5 text-sm font-semibold ui-text">
                            <AlertTriangle v-if="alert.severity !== 'info'" class="h-3.5 w-3.5" :class="severityIcon[alert.severity]" />
                            <Info v-else class="h-3.5 w-3.5" :class="severityIcon[alert.severity]" />
                            {{ alert.title }}
                        </div>
                        <p class="mt-1 text-xs ui-subtle">{{ alert.message }}</p>
                    </div>
                </div>
                <p v-else class="text-sm ui-subtle">Всё спокойно — нет активных предупреждений</p>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <h3 class="mb-4 font-display text-base font-semibold ui-text">Последние регистрации</h3>
                <div v-if="data.recent_signups.length" class="divide-y divide-border">
                    <div v-for="signup in data.recent_signups" :key="signup.id" class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                        <div class="grid size-8 shrink-0 place-items-center rounded-lg bg-accent text-xs font-bold text-accent-foreground">{{ signup.name[0] }}</div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium ui-text">{{ signup.name }}</div>
                            <div class="text-xs ui-subtle">{{ planById(signup.plan).name }}</div>
                        </div>
                        <span class="shrink-0 text-xs ui-subtle">{{ formatDate(signup.created_at) }}</span>
                    </div>
                </div>
                <p v-else class="text-sm ui-subtle">Пока нет новых компаний</p>
            </div>
        </div>
    </template>
</template>
