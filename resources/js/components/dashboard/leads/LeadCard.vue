<script setup lang="ts">
import { computed } from 'vue';
import type { Lead } from '../../../stores/crmDashboard';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { channelTone, timeAgo } from '../../../lib/format';
import { sourceLabels } from '../../../lib/statusLabels';
import { Avatar, AvatarFallback } from '../../ui/avatar';
import { Badge } from '../../ui/badge';

const props = defineProps<{ lead: Lead; customerName: string | null; selected: boolean }>();
defineEmits<{ select: [] }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();

const priority = computed<{ label: string; tone: 'red' | 'amber' | 'green' }>(() => {
    if (props.lead.score >= 70) return { label: locale.t('leads.priority.high'), tone: 'red' };
    if (props.lead.score >= 40) return { label: locale.t('leads.priority.medium'), tone: 'amber' };

    return { label: locale.t('leads.priority.low'), tone: 'green' };
});

const initials = computed(() => (props.customerName ?? props.lead.title).slice(0, 2).toUpperCase());
</script>

<template>
    <article
        class="cursor-pointer rounded-xl border bg-card p-4 transition hover:border-primary/40"
        :class="selected ? 'border-primary' : 'border-border'"
        @click="$emit('select')"
    >
        <div class="mb-3 flex items-start justify-between gap-2">
            <div class="flex flex-wrap items-center gap-1.5">
                <Badge :tone="priority.tone">{{ priority.label }}</Badge>
                <Badge v-if="lead.source" :tone="channelTone(lead.source)">{{ sourceLabels[lead.source] ?? lead.source }}</Badge>
            </div>
            <span v-if="lead.created_at" class="shrink-0 text-[11px] ui-subtle">{{ timeAgo(lead.created_at, locale.locale) }}</span>
        </div>
        <h3 class="mb-1 text-sm font-semibold ui-text">{{ lead.title }}</h3>
        <p v-if="customerName" class="mb-3 text-xs ui-subtle">{{ customerName }}</p>
        <p v-if="lead.ai_summary" class="mb-3 line-clamp-2 text-xs leading-5 ui-subtle">{{ lead.ai_summary }}</p>

        <div class="flex items-center justify-between border-t pt-3 border-border">
            <span class="rounded px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ui-text bg-muted">AI {{ lead.score }}</span>
            <Avatar class="size-7">
                <AvatarFallback class="text-[11px] font-semibold bg-accent text-accent-foreground">{{ initials }}</AvatarFallback>
            </Avatar>
        </div>

        <div v-if="lead.status !== 'won' && lead.status !== 'lost'" class="mt-3 flex items-center gap-2 border-t pt-3 border-border" @click.stop>
            <button
                v-if="lead.status === 'new'"
                class="rounded-md border px-2 py-1 text-[11px] font-medium ui-text hover:bg-muted disabled:opacity-50 border-border"

                :disabled="store.busy"
                @click="store.updateLeadStatus(lead.id, 'qualified')"
            >
                Квалифицировать
            </button>
            <button
                class="rounded-md px-2 py-1 text-[11px] font-medium disabled:opacity-50 bg-primary text-primary-foreground"

                :disabled="store.busy"
                @click="store.updateLeadStatus(lead.id, 'won')"
            >
                Выиграна
            </button>
        </div>
    </article>
</template>
