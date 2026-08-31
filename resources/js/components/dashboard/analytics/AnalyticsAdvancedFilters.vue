<script setup lang="ts">
import { useLocaleStore } from '../../../stores/locale';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';

export type Operator = { id: number; name: string };

const CHANNELS = ['telegram', 'whatsapp', 'instagram', 'facebook', 'website'];
const OUTCOMES = [
    'resolved', 'lead_created', 'sale', 'booking', 'consultation_requested', 'info_provided',
    'handed_to_operator', 'customer_stopped_responding', 'customer_unhappy', 'not_resolved',
    'ai_failed', 'operator_failed', 'technical_issue', 'spam', 'other',
];
const SENTIMENTS = ['very_happy', 'happy', 'neutral', 'unhappy', 'very_unhappy', 'angry'];

const props = defineProps<{
    channel: string; operatorId: string; outcome: string; sentiment: string; operators: Operator[];
}>();
const emit = defineEmits<{
    'update:channel': [string];
    'update:operatorId': [string];
    'update:outcome': [string];
    'update:sentiment': [string];
}>();
const locale = useLocaleStore();
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Select :model-value="props.channel" @update:model-value="(v) => emit('update:channel', String(v))">
            <SelectTrigger class="h-9 w-40"><SelectValue /></SelectTrigger>
            <SelectContent>
                <SelectItem value="all">{{ locale.t('analytics.filters.allChannels') }}</SelectItem>
                <SelectItem v-for="c in CHANNELS" :key="c" :value="c">{{ locale.t('inbox.channels.' + c) }}</SelectItem>
            </SelectContent>
        </Select>

        <Select :model-value="props.operatorId" @update:model-value="(v) => emit('update:operatorId', String(v))">
            <SelectTrigger class="h-9 w-44"><SelectValue /></SelectTrigger>
            <SelectContent>
                <SelectItem value="all">{{ locale.t('analytics.filters.allOperators') }}</SelectItem>
                <SelectItem v-for="o in props.operators" :key="o.id" :value="String(o.id)">{{ o.name }}</SelectItem>
            </SelectContent>
        </Select>

        <Select :model-value="props.outcome" @update:model-value="(v) => emit('update:outcome', String(v))">
            <SelectTrigger class="h-9 w-48"><SelectValue /></SelectTrigger>
            <SelectContent>
                <SelectItem value="all">{{ locale.t('analytics.filters.allOutcomes') }}</SelectItem>
                <SelectItem v-for="o in OUTCOMES" :key="o" :value="o">{{ locale.t('analytics.outcomes.' + o) }}</SelectItem>
            </SelectContent>
        </Select>

        <Select :model-value="props.sentiment" @update:model-value="(v) => emit('update:sentiment', String(v))">
            <SelectTrigger class="h-9 w-40"><SelectValue /></SelectTrigger>
            <SelectContent>
                <SelectItem value="all">{{ locale.t('analytics.filters.allSentiments') }}</SelectItem>
                <SelectItem v-for="s in SENTIMENTS" :key="s" :value="s">{{ locale.t('analytics.sentimentPanel.' + s) }}</SelectItem>
            </SelectContent>
        </Select>
    </div>
</template>
