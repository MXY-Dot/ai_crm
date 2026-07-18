<script setup lang="ts">
import { AlertTriangle, CheckCircle2, MessageSquare, Target } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { useCrmDashboardStore, type AiRun } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Badge } from '../ui/badge';
import { Card } from '../ui/card';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { aiHandoffs, openTasks } = storeToRefs(store);

function taskFor(run: AiRun) {
    return openTasks.value.find((task) => task.lead_id === run.lead?.id) ?? null;
}

function confidenceTone(confidence: number): 'red' | 'amber' | 'green' {
    if (confidence < 45) return 'red';
    if (confidence < 70) return 'amber';
    return 'green';
}
</script>

<template>
    <Card :title="locale.t('handoff.title')" :subtitle="locale.t('handoff.subtitle')">
        <div class="space-y-3">
            <p v-if="aiHandoffs.length === 0" class="rounded-md border border-dashed border-white/10 bg-white/[0.02] p-5 text-sm text-zinc-400">{{ locale.t('handoff.empty') }}</p>
            <article v-for="run in aiHandoffs" :key="run.id" class="rounded-md border border-amber-300/20 bg-amber-300/[0.04] p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="flex items-center gap-2 font-medium text-white"><AlertTriangle class="h-4 w-4 text-amber-200" />{{ run.intent ?? locale.t('common.unknown') }}</p>
                        <p class="mt-1 text-xs text-zinc-500">{{ run.finished_at ?? run.status }}</p>
                    </div>
                    <Badge :tone="confidenceTone(run.confidence)">{{ run.confidence }}%</Badge>
                </div>
                <p class="mt-3 text-sm leading-6 text-zinc-300">{{ run.summary }}</p>
                <div class="mt-4 grid gap-3 text-sm text-zinc-400 sm:grid-cols-2">
                    <button v-if="run.lead" class="inline-flex items-center gap-2 text-left hover:text-emerald-200" @click="store.openLead(run.lead.id)"><Target class="h-4 w-4 text-emerald-300" />{{ run.lead.title }}</button>
                    <p v-else class="inline-flex items-center gap-2"><Target class="h-4 w-4 text-emerald-300" />{{ locale.t('crm.noLead') }}</p>
                    <button v-if="run.conversation" class="inline-flex items-center gap-2 text-left hover:text-emerald-200" @click="store.openConversation(run.conversation.id)"><MessageSquare class="h-4 w-4 text-emerald-300" />{{ run.conversation.subject }}</button>
                    <p v-else class="inline-flex items-center gap-2"><MessageSquare class="h-4 w-4 text-emerald-300" />{{ locale.t('common.unknown') }}</p>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <Badge>{{ run.next_action ?? locale.t('handoff.review') }}</Badge>
                    <button v-if="taskFor(run)" class="rounded-sm border border-white/10 px-2 py-1 text-xs text-zinc-300 hover:bg-white/10" @click="store.updateTaskStatus(taskFor(run)!.id, 'in_progress')">{{ locale.t('crm.startTask') }}</button>
                    <button v-if="taskFor(run)" class="inline-flex items-center gap-1 rounded-sm border border-emerald-300/30 px-2 py-1 text-xs text-emerald-200 hover:bg-emerald-300/10" @click="store.updateTaskStatus(taskFor(run)!.id, 'done')"><CheckCircle2 class="h-3.5 w-3.5" />{{ locale.t('crm.markDone') }}</button>
                </div>
            </article>
        </div>
    </Card>
</template>