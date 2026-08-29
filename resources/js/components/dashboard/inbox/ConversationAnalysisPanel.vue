<script setup lang="ts">
import { ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { BrainCircuit } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';

type Analysis = {
    outcome: string;
    sentiment: string;
    sentiment_start: string;
    quality_score: number;
    is_resolved: boolean;
    unhappy_reason: string | null;
    summary: string | null;
    customer_wanted: string | null;
    ai_action: string | null;
    operator_action: string | null;
    return_probability: number | null;
    sale_probability: number | null;
    recommendation: string | null;
} | null;

const props = defineProps<{ conversationId: number }>();
const locale = useLocaleStore();
const { tenant } = storeToRefs(useCrmDashboardStore());

const analysis = ref<Analysis>(null);
const loading = ref(true);

async function load(): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    loading.value = true;
    try {
        analysis.value = await apiRequest<Analysis>(`/api/conversations/${props.conversationId}/analysis`, { tenant: slug });
    } catch {
        analysis.value = null;
    } finally {
        loading.value = false;
    }
}

watch(() => props.conversationId, load, { immediate: true });
</script>

<template>
    <div class="relative m-4 overflow-hidden rounded-xl border p-4 border-primary bg-card">
        <div class="mb-2 flex items-center gap-2">
            <BrainCircuit class="h-4 w-4 text-primary" />
            <h3 class="text-xs font-bold uppercase tracking-wide ui-text">{{ locale.t('analytics.chatAnalysis.title') }}</h3>
        </div>

        <p v-if="loading" class="text-[13px] ui-subtle">…</p>
        <p v-else-if="! analysis" class="text-[13px] ui-subtle">{{ locale.t('analytics.chatAnalysis.notYet') }}</p>
        <div v-else class="grid gap-1.5 text-[13px]">
            <p class="ui-text"><span class="ui-subtle">{{ locale.t('analytics.chatAnalysis.outcome') }}:</span> {{ locale.t('analytics.outcomes.' + analysis.outcome) }}</p>
            <p class="ui-text"><span class="ui-subtle">{{ locale.t('analytics.chatAnalysis.sentiment') }}:</span> {{ locale.t('analytics.sentimentPanel.' + analysis.sentiment_start) }} → {{ locale.t('analytics.sentimentPanel.' + analysis.sentiment) }}</p>
            <p class="ui-text"><span class="ui-subtle">{{ locale.t('analytics.chatAnalysis.quality') }}:</span> {{ analysis.quality_score }}%</p>
            <p v-if="analysis.summary" class="ui-text"><span class="ui-subtle">{{ locale.t('analytics.chatAnalysis.summary') }}:</span> {{ analysis.summary }}</p>
            <p v-if="analysis.unhappy_reason" class="text-destructive"><span class="ui-subtle">{{ locale.t('analytics.chatAnalysis.reason') }}:</span> {{ analysis.unhappy_reason }}</p>
            <p v-if="analysis.recommendation" class="ui-text"><span class="ui-subtle">{{ locale.t('analytics.chatAnalysis.recommendation') }}:</span> {{ analysis.recommendation }}</p>
            <p class="ui-subtle">
                {{ analysis.is_resolved ? locale.t('analytics.chatAnalysis.resolved') : locale.t('analytics.chatAnalysis.notResolvedLabel') }}
                <span v-if="analysis.sale_probability !== null"> · {{ locale.t('analytics.chatAnalysis.saleProbability') }}: {{ analysis.sale_probability }}%</span>
                <span v-if="analysis.return_probability !== null"> · {{ locale.t('analytics.chatAnalysis.returnProbability') }}: {{ analysis.return_probability }}%</span>
            </p>
        </div>
    </div>
</template>
