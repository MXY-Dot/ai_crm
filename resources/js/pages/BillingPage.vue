<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { computed } from 'vue';
import { storeToRefs } from 'pinia';
import { Receipt } from '@lucide/vue';
import CurrentPlanSummary from '../components/dashboard/billing/CurrentPlanSummary.vue';
import PlanCard from '../components/dashboard/billing/PlanCard.vue';
import UsageLimitsCard from '../components/dashboard/billing/UsageLimitsCard.vue';
import { plans, planById } from '../lib/plans';
import { useCrmDashboardStore } from '../stores/crmDashboard';

const store = useCrmDashboardStore();
const { tenant, conversations, aiAgents, busy } = storeToRefs(store);

const currentPlan = computed(() => planById(tenant.value?.settings?.billing?.plan));

defineOptions({ layout: AppLayout });
</script>

<template>
    <section class="space-y-6">
        <div>
            <h2 class="font-display text-2xl font-bold ui-text">Биллинг и тарифы</h2>
            <p class="mt-1 text-sm ui-subtle">Текущий тариф, использование лимитов и доступные планы.</p>
        </div>

        <div class="grid gap-5 lg:grid-cols-3">
            <CurrentPlanSummary :plan="currentPlan" :tenant-status="tenant?.status ?? 'trial'" :trial-ends-at="tenant?.trial_ends_at ?? null" />
            <div class="lg:col-span-2">
                <UsageLimitsCard :plan="currentPlan" :conversations-count="conversations.length" :ai-agents-count="aiAgents.length" />
            </div>
        </div>

        <div>
            <h2 class="mb-3 font-display text-base font-semibold ui-text">Тарифные планы</h2>
            <div class="grid gap-5 md:grid-cols-3">
                <PlanCard v-for="plan in plans" :key="plan.id" :plan="plan" :current="plan.id === currentPlan.id" :busy="busy" @select="store.updatePlan(plan.id)" />
            </div>
        </div>

        <div class="rounded-xl border p-6" style="border-color: var(--border); background: var(--card)">
            <h2 class="mb-3 font-display text-base font-semibold ui-text">История платежей</h2>
            <div class="flex flex-col items-center gap-2 py-8 text-center">
                <Receipt class="h-8 w-8 ui-subtle" />
                <p class="text-sm ui-subtle">Вы на пробном периоде — счетов пока нет. Они появятся после первого платёжного цикла.</p>
            </div>
        </div>
    </section>
</template>
