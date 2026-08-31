<script setup lang="ts">
import { ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { BrainCircuit } from '@lucide/vue';
import { apiRequest } from '../../../lib/apiClient';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';

type Analysis = {
    outcome: string;
    sentiment: string;
    sentiment_start: string;
    quality_score: number;
    completeness_score: number | null;
    clarity_score: number | null;
    politeness_score: number | null;
    redundant_messages_count: number | null;
    had_to_reexplain: boolean | null;
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

const OUTCOMES = [
    'resolved', 'lead_created', 'sale', 'booking', 'consultation_requested', 'info_provided',
    'handed_to_operator', 'customer_stopped_responding', 'customer_unhappy', 'not_resolved',
    'ai_failed', 'operator_failed', 'technical_issue', 'spam', 'other',
];

type TrajectoryPoint = { sent_at: string; sentiment: 'positive' | 'negative' | 'neutral' };

const TRAJECTORY_COLOR: Record<TrajectoryPoint['sentiment'], string> = {
    positive: 'bg-emerald-500',
    neutral: 'bg-muted-foreground/40',
    negative: 'bg-destructive',
};

const props = defineProps<{ conversationId: number }>();
const locale = useLocaleStore();
const { tenant } = storeToRefs(useCrmDashboardStore());

const analysis = ref<Analysis>(null);
const trajectory = ref<TrajectoryPoint[]>([]);
const loading = ref(true);
const savingOutcome = ref(false);

async function load(): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug) return;

    loading.value = true;
    try {
        const [analysisRes, trajectoryRes] = await Promise.all([
            apiRequest<Analysis>(`/api/conversations/${props.conversationId}/analysis`, { tenant: slug }),
            apiRequest<TrajectoryPoint[]>(`/api/conversations/${props.conversationId}/sentiment-trajectory`, { tenant: slug }).catch(() => []),
        ]);
        analysis.value = analysisRes;
        trajectory.value = trajectoryRes;
    } catch {
        analysis.value = null;
    } finally {
        loading.value = false;
    }
}

/** ТЗ раздел 3 — «оператор или владелец компании может вручную изменить результат». */
async function changeOutcome(outcome: string): Promise<void> {
    const slug = tenant.value?.slug;
    if (! slug || ! analysis.value || outcome === analysis.value.outcome) return;

    savingOutcome.value = true;
    try {
        analysis.value = await apiRequest<Analysis>(`/api/conversations/${props.conversationId}/analysis`, {
            method: 'PATCH', body: { outcome }, tenant: slug,
        });
        toast.success(locale.t('booking.saved'));
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        savingOutcome.value = false;
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

        <div v-if="! loading && trajectory.length > 1" class="mb-3 flex items-center gap-1">
            <span
                v-for="(point, i) in trajectory"
                :key="i"
                class="h-2 flex-1 rounded-full"
                :class="TRAJECTORY_COLOR[point.sentiment]"
                :title="new Date(point.sent_at).toLocaleString() + ': ' + locale.t('analytics.sentimentTrajectory.' + point.sentiment)"
            />
        </div>

        <p v-if="loading" class="text-[13px] ui-subtle">…</p>
        <p v-else-if="! analysis" class="text-[13px] ui-subtle">{{ locale.t('analytics.chatAnalysis.notYet') }}</p>
        <div v-else class="grid gap-1.5 text-[13px]">
            <div class="flex items-center gap-2">
                <span class="ui-subtle">{{ locale.t('analytics.chatAnalysis.outcome') }}:</span>
                <Select :model-value="analysis.outcome" :disabled="savingOutcome" @update:model-value="(v) => changeOutcome(String(v))">
                    <SelectTrigger class="h-7 w-auto min-w-0 border-none bg-transparent px-1.5 text-[13px] font-medium shadow-none"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="o in OUTCOMES" :key="o" :value="o">{{ locale.t('analytics.outcomes.' + o) }}</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <p class="ui-text"><span class="ui-subtle">{{ locale.t('analytics.chatAnalysis.sentiment') }}:</span> {{ locale.t('analytics.sentimentPanel.' + analysis.sentiment_start) }} → {{ locale.t('analytics.sentimentPanel.' + analysis.sentiment) }}</p>
            <p class="ui-text"><span class="ui-subtle">{{ locale.t('analytics.chatAnalysis.quality') }}:</span> {{ analysis.quality_score }}%</p>
            <p v-if="analysis.completeness_score !== null || analysis.clarity_score !== null || analysis.politeness_score !== null" class="ui-subtle text-xs">
                <span v-if="analysis.completeness_score !== null">{{ locale.t('analytics.chatAnalysis.completeness') }}: {{ analysis.completeness_score }}%</span>
                <span v-if="analysis.clarity_score !== null"> · {{ locale.t('analytics.chatAnalysis.clarity') }}: {{ analysis.clarity_score }}%</span>
                <span v-if="analysis.politeness_score !== null"> · {{ locale.t('analytics.chatAnalysis.politeness') }}: {{ analysis.politeness_score }}%</span>
            </p>
            <p v-if="analysis.redundant_messages_count || analysis.had_to_reexplain" class="ui-subtle text-xs">
                <span v-if="analysis.redundant_messages_count">{{ locale.t('analytics.chatAnalysis.redundantMessages') }}: {{ analysis.redundant_messages_count }}</span>
                <span v-if="analysis.redundant_messages_count && analysis.had_to_reexplain"> · </span>
                <span v-if="analysis.had_to_reexplain">{{ locale.t('analytics.chatAnalysis.hadToReexplain') }}</span>
            </p>
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
