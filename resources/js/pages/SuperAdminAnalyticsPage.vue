<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { VisArea, VisAxis, VisGroupedBar, VisXYContainer } from '@unovis/vue';
import { Bot, Building2, CircleDollarSign, Download, Gauge, MessageSquare, Percent, TrendingUp, Users } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import { planById } from '@/lib/plans';
import DateRangeFilter, { type DateRangeGranularity } from '@/components/dashboard/DateRangeFilter.vue';
import KpiCard from '@/components/dashboard/KpiCard.vue';
import LlmUsagePanel, { type LlmUsageRow } from '@/components/dashboard/analytics/LlmUsagePanel.vue';
import SlaPanel, { type Sla } from '@/components/dashboard/analytics/SlaPanel.vue';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import { Button } from '@/components/ui/button';
import { type ChartConfig, ChartContainer, ChartCrosshair, ChartTooltip, ChartTooltipContent, componentToString } from '@/components/ui/chart';

defineOptions({ layout: SuperAdminLayout });

type Analytics = {
    kpis: {
        messages_30d: number;
        conversations_30d: number;
        total_leads: number;
        conversion_rate: number;
        ai_runs_30d: number;
        avg_confidence: number;
    };
    message_trend: { date: string; label: string; count: number }[];
    leads_funnel: { status: 'new' | 'qualified' | 'won' | 'lost'; count: number; percent: number }[];
    ai: {
        agents_active: number;
        agents_paused: number;
        agents_disabled: number;
        runs_30d: number;
        avg_confidence: number;
        handoff_rate: number;
    };
    llm_usage: LlmUsageRow[];
    sla: Sla;
    channels: { provider: string; total: number; active: number; messages_30d: number }[];
    knowledge: { indexed: number; queued: number; failed: number };
    team: { by_role: { owner: number; manager: number; operator: number }; active: number; invited: number; disabled: number };
    top_tenants: { id: number; name: string; plan: string; messages_30d: number; leads: number }[];
};

const data = ref<Analytics | null>(null);
const loading = ref(true);
const granularity = ref<DateRangeGranularity>('month');
const anchorDate = ref(new Date().toISOString().slice(0, 10));

const providerLabels: Record<string, string> = { telegram: 'Telegram', whatsapp: 'WhatsApp', website: 'Виджет сайта', chatwoot: 'Единый инбокс', instagram: 'Instagram' };
const statusLabels: Record<string, string> = { new: 'Новые', qualified: 'Квалифицированные', won: 'Выиграны', lost: 'Потеряны' };
const roleLabels: Record<string, string> = { owner: 'Владельцы', manager: 'Менеджеры', operator: 'Операторы' };
const llmProviderLabels: Record<string, string> = { groq: 'Groq', openai: 'GPT', anthropic: 'Claude', google: 'Gemini', deepseek: 'DeepSeek' };

type TrendPoint = { date: string; label: string; count: number };
const trendConfig: ChartConfig = { count: { label: 'Сообщения', color: 'var(--primary)' } };

const trendData = computed<TrendPoint[]>(() => data.value?.message_trend ?? []);

const maxFunnelCount = computed(() => Math.max(1, ...(data.value?.leads_funnel.map((r) => r.count) ?? [1])));
const maxChannelMessages = computed(() => Math.max(1, ...(data.value?.channels.map((c) => c.messages_30d) ?? [1])));
const maxTeamRole = computed(() => Math.max(1, ...Object.values(data.value?.team.by_role ?? { a: 1 })));

type LlmUsagePoint = { provider: string; label: string; requests: number; tokens_in: number; tokens_out: number; cost_usd: number };
const llmCostConfig: ChartConfig = { cost_usd: { label: 'Расход, $', color: 'var(--primary)' } };
const llmTokensConfig: ChartConfig = {
    tokens_in: { label: 'Входящие токены', color: 'var(--primary)' },
    tokens_out: { label: 'Исходящие токены', color: 'var(--accent-foreground)' },
};
const llmUsageData = computed<LlmUsagePoint[]>(() => (data.value?.llm_usage ?? []).map((row) => ({ ...row, label: llmProviderLabels[row.provider] ?? row.provider })));
const totalLlmCost = computed(() => llmUsageData.value.reduce((sum, row) => sum + row.cost_usd, 0));
const totalLlmTokens = computed(() => llmUsageData.value.reduce((sum, row) => sum + row.tokens_in + row.tokens_out, 0));
function formatMoney(value: number): string {
    return '$' + value.toFixed(value < 1 ? 4 : 2);
}

const knowledgeTotal = computed(() => {
    const k = data.value?.knowledge;
    return k ? k.indexed + k.queued + k.failed : 0;
});

function knowledgePercent(count: number): number {
    return knowledgeTotal.value > 0 ? Math.round((count / knowledgeTotal.value) * 100) : 0;
}

async function load(): Promise<void> {
    loading.value = true;
    try {
        const params = new URLSearchParams({ range: granularity.value, date: anchorDate.value });
        data.value = await apiRequest<Analytics>(`/api/admin/analytics?${params.toString()}`);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить аналитику платформы');
    } finally {
        loading.value = false;
    }
}

onMounted(load);
watch([granularity, anchorDate], load);

function csvEscape(value: string): string {
    return `"${value.replace(/"/g, '""')}"`;
}

function exportCsv(): void {
    if (! data.value) return;
    const d = data.value;
    const rows: string[][] = [
        ['Аналитика платформы WERO', new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date())],
        [],
        ['Ключевые метрики (за выбранный период)'],
        ['Сообщений', String(d.kpis.messages_30d)],
        ['Диалогов открыто', String(d.kpis.conversations_30d)],
        ['Всего лидов', String(d.kpis.total_leads)],
        ['Конверсия в выигранные', `${d.kpis.conversion_rate}%`],
        ['AI-запусков', String(d.kpis.ai_runs_30d)],
        ['Средняя уверенность AI', `${d.kpis.avg_confidence}%`],
        [],
        ['Сообщения по дням'],
        ['Дата', 'Сообщений'],
        ...d.message_trend.map((p) => [p.date, String(p.count)]),
        [],
        ['Воронка лидов'],
        ['Статус', 'Количество', 'Доля'],
        ...d.leads_funnel.map((r) => [statusLabels[r.status] ?? r.status, String(r.count), `${r.percent}%`]),
        [],
        ['AI-производительность'],
        ['Активные агенты', String(d.ai.agents_active)],
        ['На паузе', String(d.ai.agents_paused)],
        ['Отключены', String(d.ai.agents_disabled)],
        ['Запусков за период', String(d.ai.runs_30d)],
        ['Средняя уверенность', `${d.ai.avg_confidence}%`],
        ['Доля передач оператору', `${d.ai.handoff_rate}%`],
        [],
        ['Расход по AI-провайдерам (за период)'],
        ['Провайдер', 'Запросов', 'Токены (вход)', 'Токены (выход)', 'Расход, $'],
        ...d.llm_usage.map((r) => [llmProviderLabels[r.provider] ?? r.provider, String(r.requests), String(r.tokens_in), String(r.tokens_out), r.cost_usd.toFixed(4)]),
        [],
        ['Каналы связи'],
        ['Провайдер', 'Активны', 'Всего', 'Сообщений за период'],
        ...d.channels.map((c) => [providerLabels[c.provider] ?? c.provider, String(c.active), String(c.total), String(c.messages_30d)]),
        [],
        ['База знаний'],
        ['Проиндексировано', String(d.knowledge.indexed)],
        ['В очереди', String(d.knowledge.queued)],
        ['Ошибки', String(d.knowledge.failed)],
        [],
        ['Команды платформы'],
        ['Владельцы', String(d.team.by_role.owner)],
        ['Менеджеры', String(d.team.by_role.manager)],
        ['Операторы', String(d.team.by_role.operator)],
        ['Активны', String(d.team.active)],
        ['Приглашены', String(d.team.invited)],
        ['Отключены', String(d.team.disabled)],
        [],
        ['Топ компаний по активности (за период)'],
        ['Компания', 'Тариф', 'Сообщений', 'Лидов'],
        ...d.top_tenants.map((t) => [t.name, planById(t.plan).name, String(t.messages_30d), String(t.leads)]),
    ];

    const csv = rows.map((row) => row.map(csvEscape).join(',')).join('\n');
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `wero-platform-analytics-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-display text-xl font-bold ui-text">Аналитика платформы</h2>
            <p class="mt-1 text-sm ui-subtle">Сквозная аналитика по всем компаниям: сообщения, лиды, AI, каналы, команды.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <DateRangeFilter v-model:granularity="granularity" v-model:anchor="anchorDate" />
            <Button variant="outline" size="sm" :disabled="loading || !data" @click="exportCsv"><Download class="h-4 w-4" />Экспорт CSV</Button>
        </div>
    </div>

    <div v-if="loading" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <Skeleton v-for="i in 6" :key="i" class="h-28 rounded-xl" />
    </div>

    <template v-else-if="data">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <KpiCard label="Сообщения за период" :value="data.kpis.messages_30d.toLocaleString('ru-RU')" :hint="`${data.kpis.conversations_30d.toLocaleString('ru-RU')} новых диалогов`">
                <template #icon><MessageSquare class="h-4 w-4 ui-subtle" /></template>
            </KpiCard>
            <KpiCard label="Конверсия лидов" :value="`${data.kpis.conversion_rate}%`" :hint="`${data.kpis.total_leads.toLocaleString('ru-RU')} лидов всего`">
                <template #icon><Percent class="h-4 w-4 text-primary" /></template>
            </KpiCard>
            <KpiCard label="AI-запуски за период" :value="data.kpis.ai_runs_30d.toLocaleString('ru-RU')" :hint="`Средняя уверенность ${data.kpis.avg_confidence}%`">
                <template #icon><Bot class="h-4 w-4 ui-subtle" /></template>
            </KpiCard>
        </div>

        <div class="rounded-xl border border-border bg-card p-5">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h3 class="font-display text-base font-semibold ui-text">Сообщения по дням</h3>
                    <p class="text-xs ui-subtle">Все компании, за выбранный период</p>
                </div>
                <TrendingUp class="h-4 w-4 text-primary" />
            </div>
            <ChartContainer :config="trendConfig" class="h-64 w-full">
                <VisXYContainer :data="trendData">
                    <VisArea
                        :x="(d: TrendPoint, i: number) => i"
                        :y="(d: TrendPoint) => d.count"
                        :color="trendConfig.count.color"
                        :opacity="0.15"
                        :line="true"
                        :line-width="2.5"
                        :line-color="trendConfig.count.color"
                    />
                    <VisAxis
                        type="x"
                        :x="(d: TrendPoint, i: number) => i"
                        :tick-line="false"
                        :domain-line="false"
                        :grid-line="false"
                        :num-ticks="6"
                        :tick-format="(i: number) => trendData[i]?.label ?? ''"
                    />
                    <VisAxis type="y" :tick-format="() => ''" :tick-line="false" :domain-line="false" :grid-line="true" />
                    <ChartTooltip />
                    <ChartCrosshair :template="componentToString(trendConfig, ChartTooltipContent)" :color="[trendConfig.count.color]" />
                </VisXYContainer>
            </ChartContainer>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-border bg-card p-5">
                <h3 class="font-display text-base font-semibold ui-text">Воронка лидов</h3>
                <p class="mb-5 text-xs ui-subtle">Статусы лидов по всей платформе</p>
                <div class="space-y-4">
                    <div v-for="row in data.leads_funnel" :key="row.status">
                        <div class="mb-1.5 flex items-center justify-between text-sm">
                            <span class="ui-text">{{ statusLabels[row.status] }}</span>
                            <span class="font-mono text-xs ui-subtle">{{ row.count }} · {{ row.percent }}%</span>
                        </div>
                        <Progress :model-value="(row.count / maxFunnelCount) * 100" />
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <div class="mb-1 flex items-center gap-2">
                    <Gauge class="h-4 w-4 text-primary" />
                    <h3 class="font-display text-base font-semibold ui-text">AI-производительность</h3>
                </div>
                <p class="mb-5 text-xs ui-subtle">Агенты и запуски по всей платформе</p>
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-lg border border-border p-3">
                        <p class="font-display text-2xl font-bold ui-text">{{ data.ai.agents_active }}</p>
                        <p class="mt-1 text-xs ui-subtle">Активны</p>
                    </div>
                    <div class="rounded-lg border border-border p-3">
                        <p class="font-display text-2xl font-bold ui-text">{{ data.ai.agents_paused }}</p>
                        <p class="mt-1 text-xs ui-subtle">На паузе</p>
                    </div>
                    <div class="rounded-lg border border-border p-3">
                        <p class="font-display text-2xl font-bold ui-text">{{ data.ai.agents_disabled }}</p>
                        <p class="mt-1 text-xs ui-subtle">Отключены</p>
                    </div>
                </div>
                <div class="mt-4 space-y-3">
                    <div>
                        <div class="mb-1.5 flex items-center justify-between text-sm">
                            <span class="ui-text">Средняя уверенность</span>
                            <span class="font-mono text-xs ui-subtle">{{ data.ai.avg_confidence }}%</span>
                        </div>
                        <Progress :model-value="data.ai.avg_confidence" />
                    </div>
                    <div>
                        <div class="mb-1.5 flex items-center justify-between text-sm">
                            <span class="ui-text">Передано оператору</span>
                            <span class="font-mono text-xs ui-subtle">{{ data.ai.handoff_rate }}%</span>
                        </div>
                        <Progress :model-value="data.ai.handoff_rate" />
                    </div>
                </div>
            </div>
        </div>

        <SlaPanel :data="data.sla" :loading="loading" />

        <div class="rounded-xl border border-border bg-card p-5">
            <div class="mb-1 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <CircleDollarSign class="h-4 w-4 text-primary" />
                    <h3 class="font-display text-base font-semibold ui-text">Расход по AI-провайдерам</h3>
                </div>
                <span class="text-xs ui-subtle">{{ formatMoney(totalLlmCost) }} · {{ totalLlmTokens.toLocaleString('ru-RU') }} токенов за период</span>
            </div>
            <p class="mb-5 text-xs ui-subtle">Groq, GPT, Claude, Gemini, DeepSeek — платформенные ключи, управляются в разделе «LLM-провайдеры»</p>
            <div class="grid gap-6 lg:grid-cols-2">
                <div>
                    <p class="mb-2 text-xs font-semibold uppercase ui-subtle">Расход, $</p>
                    <ChartContainer :config="llmCostConfig" class="h-56 w-full">
                        <VisXYContainer :data="llmUsageData">
                            <VisGroupedBar
                                :x="(d: LlmUsagePoint, i: number) => i"
                                :y="[(d: LlmUsagePoint) => d.cost_usd]"
                                :color="[llmCostConfig.cost_usd.color]"
                                :rounded-corners="4"
                            />
                            <VisAxis
                                type="x"
                                :x="(d: LlmUsagePoint, i: number) => i"
                                :tick-line="false"
                                :domain-line="false"
                                :grid-line="false"
                                :tick-format="(i: number) => llmUsageData[i]?.label ?? ''"
                            />
                            <VisAxis type="y" :tick-format="() => ''" :tick-line="false" :domain-line="false" :grid-line="true" />
                            <ChartTooltip />
                            <ChartCrosshair :template="componentToString(llmCostConfig, ChartTooltipContent)" :color="[llmCostConfig.cost_usd.color]" />
                        </VisXYContainer>
                    </ChartContainer>
                </div>
                <div>
                    <p class="mb-2 text-xs font-semibold uppercase ui-subtle">Токены (вход / выход)</p>
                    <ChartContainer :config="llmTokensConfig" class="h-56 w-full">
                        <VisXYContainer :data="llmUsageData">
                            <VisGroupedBar
                                :x="(d: LlmUsagePoint, i: number) => i"
                                :y="[(d: LlmUsagePoint) => d.tokens_in, (d: LlmUsagePoint) => d.tokens_out]"
                                :color="[llmTokensConfig.tokens_in.color, llmTokensConfig.tokens_out.color]"
                                :rounded-corners="4"
                            />
                            <VisAxis
                                type="x"
                                :x="(d: LlmUsagePoint, i: number) => i"
                                :tick-line="false"
                                :domain-line="false"
                                :grid-line="false"
                                :tick-format="(i: number) => llmUsageData[i]?.label ?? ''"
                            />
                            <VisAxis type="y" :tick-format="() => ''" :tick-line="false" :domain-line="false" :grid-line="true" />
                            <ChartTooltip />
                            <ChartCrosshair
                                :template="componentToString(llmTokensConfig, ChartTooltipContent)"
                                :color="[llmTokensConfig.tokens_in.color, llmTokensConfig.tokens_out.color]"
                            />
                        </VisXYContainer>
                    </ChartContainer>
                </div>
            </div>
            <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div v-for="row in llmUsageData" :key="row.provider" class="rounded-lg border border-border p-3">
                    <p class="text-sm font-medium ui-text">{{ row.label }}</p>
                    <p class="mt-1 font-mono text-xs ui-subtle">{{ row.requests }} запр. · {{ formatMoney(row.cost_usd) }}</p>
                </div>
            </div>
        </div>

        <LlmUsagePanel :data="data.llm_usage" :loading="loading" />

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-xl border border-border bg-card p-5">
                <h3 class="mb-4 font-display text-base font-semibold ui-text">Каналы связи</h3>
                <div v-if="data.channels.length" class="space-y-4">
                    <div v-for="channel in data.channels" :key="channel.provider">
                        <div class="mb-1.5 flex items-center justify-between text-sm">
                            <span class="ui-text">{{ providerLabels[channel.provider] ?? channel.provider }}</span>
                            <span class="font-mono text-xs ui-subtle">{{ channel.messages_30d }} сообщ. · {{ channel.active }}/{{ channel.total }} активны</span>
                        </div>
                        <Progress :model-value="(channel.messages_30d / maxChannelMessages) * 100" />
                    </div>
                </div>
                <p v-else class="text-sm ui-subtle">Каналы ещё не подключены</p>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <h3 class="mb-4 font-display text-base font-semibold ui-text">База знаний</h3>
                <div class="space-y-4">
                    <div>
                        <div class="mb-1.5 flex items-center justify-between text-sm">
                            <span class="ui-text">Проиндексировано</span>
                            <span class="font-mono text-xs ui-subtle">{{ data.knowledge.indexed }} · {{ knowledgePercent(data.knowledge.indexed) }}%</span>
                        </div>
                        <Progress :model-value="knowledgePercent(data.knowledge.indexed)" />
                    </div>
                    <div>
                        <div class="mb-1.5 flex items-center justify-between text-sm">
                            <span class="ui-text">В очереди</span>
                            <span class="font-mono text-xs ui-subtle">{{ data.knowledge.queued }} · {{ knowledgePercent(data.knowledge.queued) }}%</span>
                        </div>
                        <Progress :model-value="knowledgePercent(data.knowledge.queued)" />
                    </div>
                    <div>
                        <div class="mb-1.5 flex items-center justify-between text-sm">
                            <span class="ui-text">Ошибки</span>
                            <span class="font-mono text-xs ui-subtle">{{ data.knowledge.failed }} · {{ knowledgePercent(data.knowledge.failed) }}%</span>
                        </div>
                        <Progress :model-value="knowledgePercent(data.knowledge.failed)" />
                    </div>
                </div>
                <p v-if="! knowledgeTotal" class="mt-2 text-sm ui-subtle">Документы ещё не загружены</p>
            </div>

            <div class="rounded-xl border border-border bg-card p-5">
                <div class="mb-4 flex items-center gap-2">
                    <Users class="h-4 w-4 text-primary" />
                    <h3 class="font-display text-base font-semibold ui-text">Команды платформы</h3>
                </div>
                <div class="space-y-4">
                    <div v-for="(count, role) in data.team.by_role" :key="role">
                        <div class="mb-1.5 flex items-center justify-between text-sm">
                            <span class="ui-text">{{ roleLabels[role] ?? role }}</span>
                            <span class="font-mono text-xs ui-subtle">{{ count }}</span>
                        </div>
                        <Progress :model-value="(count / maxTeamRole) * 100" />
                    </div>
                </div>
                <div class="mt-4 flex items-center justify-between border-t border-border pt-3 text-xs ui-subtle">
                    <span>{{ data.team.active }} активны</span>
                    <span>{{ data.team.invited }} приглашены</span>
                    <span>{{ data.team.disabled }} отключены</span>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-border bg-card p-5">
            <div class="mb-4 flex items-center gap-2">
                <Building2 class="h-4 w-4 text-primary" />
                <h3 class="font-display text-base font-semibold ui-text">Топ компаний по активности</h3>
                <span class="text-xs ui-subtle">за выбранный период</span>
            </div>
            <div v-if="data.top_tenants.length" class="divide-y divide-border">
                <div v-for="(tenant, index) in data.top_tenants" :key="tenant.id" class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                    <span class="grid size-7 shrink-0 place-items-center rounded-full bg-muted font-mono text-xs font-semibold ui-subtle">{{ index + 1 }}</span>
                    <div class="grid size-8 shrink-0 place-items-center rounded-lg bg-accent text-xs font-bold text-accent-foreground">{{ tenant.name[0] }}</div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium ui-text">{{ tenant.name }}</div>
                        <div class="text-xs ui-subtle">{{ planById(tenant.plan).name }} · {{ tenant.leads }} лидов</div>
                    </div>
                    <span class="shrink-0 font-mono text-sm font-semibold ui-text">{{ tenant.messages_30d }} сообщ.</span>
                </div>
            </div>
            <p v-else class="text-sm ui-subtle">Пока нет активности за этот период</p>
        </div>
    </template>
</template>
