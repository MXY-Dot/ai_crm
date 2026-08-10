<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Bot, Save } from '@lucide/vue';
import type { AiAgent, KnowledgeDocument } from '../../../stores/crmDashboard';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { Button } from '../../ui/button';
import { Input } from '../../ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Textarea } from '../../ui/textarea';

const props = defineProps<{ agent: AiAgent | null; documents: KnowledgeDocument[]; busy: boolean }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();

const form = reactive({ name: '', status: 'active' as 'active' | 'paused' | 'disabled', handoff_threshold: 70, instructions: '' });

watch(() => props.agent, (agent) => {
    if (! agent) return;
    form.name = agent.name;
    form.status = ['active', 'paused', 'disabled'].includes(agent.status) ? agent.status as typeof form.status : 'active';
    form.handoff_threshold = agent.handoff_threshold;
    form.instructions = agent.instructions ?? '';
}, { immediate: true });

async function save(): Promise<void> {
    if (! props.agent) return;
    await store.updateAiAgent(props.agent.id, {
        name: form.name.trim(),
        status: form.status,
        handoff_threshold: Number(form.handoff_threshold),
        instructions: form.instructions.trim(),
    });
}
</script>

<template>
    <div class="flex flex-col rounded-xl border border-border bg-card">
        <div class="flex items-center justify-between border-b p-4 border-border">
            <h2 class="flex items-center gap-2 font-display text-base font-semibold ui-text"><Bot class="h-4 w-4 text-primary" />{{ agent ? `Настройки: ${agent.name}` : 'Выберите ассистента' }}</h2>
            <Button v-if="agent" variant="outline" size="sm" :disabled="busy" @click="save"><Save class="h-4 w-4" />{{ busy ? locale.t('common.waiting') : locale.t('ai.saveAgent') }}</Button>
        </div>
        <form v-if="agent" class="flex flex-1 flex-col gap-4 overflow-y-auto p-4" @submit.prevent="save">
            <div class="grid gap-3 sm:grid-cols-2">
                <label>
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('ai.agentName') }}</span>
                    <Input v-model="form.name" maxlength="120" required />
                </label>
                <label>
                    <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('ai.agentStatus') }}</span>
                    <Select v-model="form.status">
                        <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="active">{{ locale.t('ai.status.active') }}</SelectItem>
                            <SelectItem value="paused">{{ locale.t('ai.status.paused') }}</SelectItem>
                            <SelectItem value="disabled">{{ locale.t('ai.status.disabled') }}</SelectItem>
                        </SelectContent>
                    </Select>
                </label>
            </div>
            <label>
                <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('ai.instructions') }}</span>
                <Textarea v-model="form.instructions" class="min-h-32 font-mono" maxlength="4000" />
            </label>
            <label class="max-w-xs">
                <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('ai.handoffThreshold') }}</span>
                <Input v-model.number="form.handoff_threshold" type="number" min="1" max="100" />
            </label>
            <div>
                <span class="mb-2 block text-xs font-semibold uppercase ui-subtle">Источники знаний</span>
                <div class="divide-y overflow-hidden rounded-lg border border-border">
                    <p v-for="doc in documents" :key="doc.id" class="px-3 py-2 text-sm ui-text">{{ doc.title }}</p>
                    <p v-if="! documents.length" class="px-3 py-2 text-sm ui-subtle">Документы не привязаны</p>
                </div>
            </div>
        </form>
    </div>
</template>
