<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Lightbulb, MessageCircleQuestion, RefreshCw, TrendingDown } from '@lucide/vue';
import SuperAdminLayout from '@/layouts/SuperAdminLayout.vue';
import { apiRequest } from '@/lib/apiClient';
import KpiCard from '@/components/dashboard/KpiCard.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

defineOptions({ layout: SuperAdminLayout });

type KnowledgeGapRow = { id: number; tenant_name: string | null; company_name: string | null; customer_message: string; created_at: string };
type TenantGapCount = { tenant_id: number; name: string; total: number };
type KnowledgeGaps = { total_30d: number; by_tenant: TenantGapCount[]; recent: KnowledgeGapRow[] };

type LostLeadRow = { id: number; title: string; tenant_name: string | null; company_name: string | null; amount: number | null; lost_reason: string | null; updated_at: string };
type ReasonCount = { reason: string; total: number; percent: number };
type LostLeads = { total: number; by_reason: ReasonCount[]; recent: LostLeadRow[] };

const gaps = ref<KnowledgeGaps | null>(null);
const lostLeads = ref<LostLeads | null>(null);
const loading = ref(true);

async function load(): Promise<void> {
    loading.value = true;
    try {
        [gaps.value, lostLeads.value] = await Promise.all([
            apiRequest<KnowledgeGaps>('/api/admin/insights/knowledge-gaps'),
            apiRequest<LostLeads>('/api/admin/insights/lost-leads'),
        ]);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Не удалось загрузить инсайты');
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-display text-xl font-bold ui-text">AI-инсайты</h2>
            <p class="mt-1 text-sm ui-subtle">Этап 19 — чего не хватает базам знаний компаний и почему срываются сделки, по реальным данным платформы.</p>
        </div>
        <Button variant="outline" size="sm" :disabled="loading" @click="load"><RefreshCw class="h-4 w-4" />Обновить</Button>
    </div>

    <div v-if="loading" class="grid gap-4 md:grid-cols-2">
        <Skeleton class="h-64 rounded-xl" />
        <Skeleton class="h-64 rounded-xl" />
    </div>

    <template v-else>
        <section>
            <div class="mb-3 flex items-center gap-2">
                <MessageCircleQuestion class="h-4 w-4 text-primary" />
                <h3 class="font-display text-base font-semibold ui-text">Пробелы в базе знаний</h3>
            </div>
            <p class="mb-4 text-sm ui-subtle">
                Срабатывает каждый раз, когда AI не нашёл в базе знаний компании ничего релевантного вопросу клиента
                (защита от галлюцинаций) — это и есть реальный пробел, который стоит закрыть документом.
            </p>

            <div class="grid gap-4 md:grid-cols-3">
                <KpiCard label="Пробелов за 30 дней" :value="String(gaps?.total_30d ?? 0)" hint="По всей платформе">
                    <template #icon><Lightbulb class="h-4 w-4 text-primary" /></template>
                </KpiCard>

                <div class="rounded-xl border border-border bg-card p-4 md:col-span-2">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wide ui-subtle">По компаниям (30 дней)</p>
                    <div v-if="gaps?.by_tenant.length" class="space-y-2">
                        <div v-for="row in gaps.by_tenant" :key="row.tenant_id" class="flex items-center gap-3">
                            <span class="w-32 shrink-0 truncate text-xs ui-text">{{ row.name }}</span>
                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                <div
                                    class="h-full rounded-full bg-primary"
                                    :style="{ width: `${Math.min(100, (row.total / gaps.by_tenant[0].total) * 100)}%` }"
                                />
                            </div>
                            <span class="w-6 shrink-0 text-right font-mono text-xs ui-subtle">{{ row.total }}</span>
                        </div>
                    </div>
                    <p v-else class="text-xs ui-subtle">Пока пусто — как только у компаний появятся вопросы без ответа в базе знаний, они будут здесь.</p>
                </div>
            </div>

            <div class="mt-4 rounded-xl border border-border bg-card p-4">
                <p class="mb-3 text-xs font-semibold uppercase tracking-wide ui-subtle">Последние вопросы без ответа в базе</p>
                <div v-if="gaps?.recent.length" class="divide-y divide-border">
                    <div v-for="row in gaps.recent" :key="row.id" class="flex items-start justify-between gap-4 py-2.5 text-sm">
                        <div class="min-w-0">
                            <p class="truncate ui-text">{{ row.customer_message }}</p>
                            <p class="mt-0.5 text-xs ui-subtle">{{ row.company_name ?? row.tenant_name ?? '—' }}</p>
                        </div>
                        <span class="shrink-0 font-mono text-xs ui-subtle">{{ formatDate(row.created_at) }}</span>
                    </div>
                </div>
                <p v-else class="text-xs ui-subtle">Пока ничего не зафиксировано.</p>
            </div>
        </section>

        <section class="mt-8">
            <div class="mb-3 flex items-center gap-2">
                <TrendingDown class="h-4 w-4 text-destructive" />
                <h3 class="font-display text-base font-semibold ui-text">Причины потерянных лидов</h3>
            </div>
            <p class="mb-4 text-sm ui-subtle">
                Считается по лидам со статусом «Проиграна» — причину указывает оператор при переводе лида в этот статус
                на странице «Лиды».
            </p>

            <div class="grid gap-4 md:grid-cols-3">
                <KpiCard label="Проигранных лидов" :value="String(lostLeads?.total ?? 0)" hint="По всей платформе">
                    <template #icon><TrendingDown class="h-4 w-4 text-destructive" /></template>
                </KpiCard>

                <div class="rounded-xl border border-border bg-card p-4 md:col-span-2">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wide ui-subtle">По причине</p>
                    <div v-if="lostLeads?.by_reason.length" class="space-y-2">
                        <div v-for="row in lostLeads.by_reason" :key="row.reason" class="flex items-center gap-3">
                            <span class="w-36 shrink-0 truncate text-xs ui-text">{{ row.reason }}</span>
                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                <div class="h-full rounded-full bg-destructive/70" :style="{ width: `${row.percent}%` }" />
                            </div>
                            <span class="w-14 shrink-0 text-right font-mono text-xs ui-subtle">{{ row.total }} ({{ row.percent }}%)</span>
                        </div>
                    </div>
                    <p v-else class="text-xs ui-subtle">Пока ни один лид не отмечен проигранным с причиной.</p>
                </div>
            </div>

            <div class="mt-4 rounded-xl border border-border bg-card p-4">
                <p class="mb-3 text-xs font-semibold uppercase tracking-wide ui-subtle">Последние проигранные лиды</p>
                <div v-if="lostLeads?.recent.length" class="divide-y divide-border">
                    <div v-for="row in lostLeads.recent" :key="row.id" class="flex items-start justify-between gap-4 py-2.5 text-sm">
                        <div class="min-w-0">
                            <p class="truncate ui-text">{{ row.title }}</p>
                            <p class="mt-0.5 text-xs ui-subtle">{{ row.company_name ?? row.tenant_name ?? '—' }} · {{ row.lost_reason ?? 'Не указано' }}</p>
                        </div>
                        <span class="shrink-0 font-mono text-xs ui-subtle">{{ formatDate(row.updated_at) }}</span>
                    </div>
                </div>
                <p v-else class="text-xs ui-subtle">Пока ничего не зафиксировано.</p>
            </div>
        </section>
    </template>
</template>
