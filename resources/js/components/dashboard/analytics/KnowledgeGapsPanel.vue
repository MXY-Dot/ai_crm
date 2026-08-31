<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { apiRequest } from '../../../lib/apiClient';
import { useLocaleStore } from '../../../stores/locale';
import { Card } from '../../ui/card';
import { Skeleton } from '../../ui/skeleton';

type GapRow = { id: number; customer_message: string; created_at: string };
type TopicRow = { topic: string; gap_count: number; asked_count: number; answered_rate: number; recommendation: string };

const props = defineProps<{ queryString: string; tenantSlug: string }>();
const locale = useLocaleStore();

const rows = ref<GapRow[]>([]);
const topics = ref<TopicRow[]>([]);
const loading = ref(true);

async function load(): Promise<void> {
    if (! props.tenantSlug) return;
    loading.value = true;
    try {
        const data = await apiRequest<{ data: GapRow[]; topics: TopicRow[] }>(`/api/analytics/knowledge-gaps?${props.queryString}`, { tenant: props.tenantSlug });
        rows.value = data.data;
        topics.value = data.topics;
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Error');
    } finally {
        loading.value = false;
    }
}

onMounted(load);
watch(() => [props.queryString, props.tenantSlug], load);
</script>

<template>
    <Card :title="locale.t('analytics.knowledgeGapsPanel.title')" :subtitle="locale.t('analytics.knowledgeGapsPanel.subtitle')">
        <div v-if="loading" class="space-y-2 pb-4">
            <Skeleton v-for="i in 3" :key="i" class="h-8 rounded-lg" />
        </div>
        <p v-else-if="! rows.length" class="pb-4 text-sm ui-subtle">{{ locale.t('analytics.knowledgeGapsPanel.empty') }}</p>
        <template v-else>
            <div v-if="topics.length" class="mb-3 space-y-2 border-b border-border pb-3">
                <div v-for="t in topics" :key="t.topic" class="rounded-lg bg-muted/40 px-3 py-2 text-sm">
                    <p class="ui-text">{{ t.recommendation }}</p>
                </div>
            </div>
            <div class="max-h-72 space-y-2 overflow-y-auto pb-4 text-sm">
                <div v-for="row in rows" :key="row.id" class="rounded-lg border border-border px-3 py-2">
                    <p class="ui-text">{{ row.customer_message }}</p>
                    <p class="mt-1 text-xs ui-subtle">{{ new Date(row.created_at).toLocaleString() }}</p>
                </div>
            </div>
        </template>
    </Card>
</template>
