<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Bot, BookOpen, Cpu, Lock, Save, SlidersHorizontal } from '@lucide/vue';
import type { AiAgent, KnowledgeDocument } from '../../../stores/crmDashboard';
import { useCrmDashboardStore } from '../../../stores/crmDashboard';
import { useLocaleStore } from '../../../stores/locale';
import { sourceLabels } from '../../../lib/statusLabels';
import { planById, plans, providerForModel } from '../../../lib/plans';
import { Button } from '../../ui/button';
import { Input } from '../../ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../../ui/select';
import { Textarea } from '../../ui/textarea';

const props = defineProps<{ agent: AiAgent | null; documents: KnowledgeDocument[]; allDocuments: KnowledgeDocument[]; busy: boolean }>();
const store = useCrmDashboardStore();
const locale = useLocaleStore();

const MODEL_OPTIONS = ['openai/gpt-oss-120b', 'openai/gpt-oss-20b', 'gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'deepseek-chat', 'deepseek-reasoner', 'claude-3-5-sonnet-latest', 'claude-3-5-haiku-latest', 'gemini-1.5-pro', 'gemini-1.5-flash'];
const currentPlan = computed(() => planById(store.tenant?.settings?.billing?.plan));
function isModelLocked(model: string): boolean {
    const provider = providerForModel(model);
    return provider !== null && ! currentPlan.value.aiProviders.includes(provider);
}
const maxAiProviders = Math.max(...plans.map((plan) => plan.aiProviders.length));
const CHANNEL_OPTIONS = ['telegram', 'whatsapp', 'instagram', 'website'];
const CHANNEL_STYLES: Record<string, string> = {
    telegram: 'border-brand-telegram/50 bg-brand-telegram/15 text-brand-telegram',
    whatsapp: 'border-brand-whatsapp/50 bg-brand-whatsapp/15 text-brand-whatsapp',
    instagram: 'border-brand-instagram-to/50 bg-brand-instagram-to/15 text-brand-instagram-to',
    website: 'border-sky-600/50 bg-sky-600/15 text-sky-700 dark:text-sky-300',
    chatwoot: 'border-emerald-600/50 bg-emerald-600/15 text-emerald-700 dark:text-emerald-300',
};

const form = reactive({ name: '', status: 'active' as 'active' | 'paused' | 'disabled', handoff_threshold: 70, instructions: '', model: '', modelCustom: '' });
const selectedDocIds = ref<number[]>([]);
const selectedChannels = ref<string[]>([]);

watch(() => props.agent, (agent) => {
    if (! agent) return;
    form.name = agent.name;
    form.status = ['active', 'paused', 'disabled'].includes(agent.status) ? agent.status as typeof form.status : 'active';
    form.handoff_threshold = agent.handoff_threshold;
    form.instructions = agent.instructions ?? '';
    const model = agent.model ?? '';
    if (model && ! MODEL_OPTIONS.includes(model)) {
        form.model = '__custom__';
        form.modelCustom = model;
    } else {
        form.model = model;
        form.modelCustom = '';
    }
    selectedChannels.value = agent.channels ?? [];
}, { immediate: true });

watch(() => props.documents, (documents) => {
    selectedDocIds.value = documents.map((doc) => doc.id);
}, { immediate: true });

function toggleDoc(id: number, checked: boolean): void {
    selectedDocIds.value = checked
        ? [...selectedDocIds.value, id]
        : selectedDocIds.value.filter((docId) => docId !== id);
}

function toggleChannel(channel: string): void {
    selectedChannels.value = selectedChannels.value.includes(channel)
        ? selectedChannels.value.filter((item) => item !== channel)
        : [...selectedChannels.value, channel];
}

async function save(): Promise<void> {
    if (! props.agent) return;
    const model = form.model === '__custom__' ? form.modelCustom.trim() : form.model;
    // Snapshot before the first await: updateAiAgent() refreshes the whole dashboard
    // internally, which reassigns `documents` with fresh (pre-sync) data and re-triggers
    // the watcher above that resets selectedDocIds — reading selectedDocIds.value *after*
    // that await would silently send the stale, already-wiped selection to syncAgentKnowledge.
    const documentIds = [...selectedDocIds.value];
    await store.updateAiAgent(props.agent.id, {
        name: form.name.trim(),
        status: form.status,
        handoff_threshold: Number(form.handoff_threshold),
        instructions: form.instructions.trim(),
        model: model || null,
        channels: selectedChannels.value,
    });
    await store.syncAgentKnowledge(props.agent.id, documentIds);
}
</script>

<template>
    <div class="flex flex-col rounded-xl border border-border bg-card">
        <div class="flex items-center justify-between border-b p-4 border-border">
            <h2 class="flex items-center gap-2 font-display text-base font-semibold ui-text"><Bot class="h-4 w-4 text-primary" />{{ agent ? `Настройки: ${agent.name}` : 'Выберите ассистента' }}</h2>
            <Button v-if="agent" variant="outline" size="sm" :disabled="busy" @click="save"><Save class="h-4 w-4" />{{ busy ? locale.t('common.waiting') : locale.t('ai.saveAgent') }}</Button>
        </div>
        <form v-if="agent" class="flex flex-1 flex-col gap-4 overflow-y-auto p-4" @submit.prevent="save">
            <div class="grid gap-4 xl:grid-cols-2">
                <div class="rounded-xl border border-border bg-background/40 p-4">
                    <div class="mb-3 flex items-center gap-2">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary"><SlidersHorizontal class="h-4 w-4" /></span>
                        <h3 class="text-sm font-semibold ui-text">Основное</h3>
                    </div>
                    <div class="flex flex-col gap-3">
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
                            <Textarea v-model="form.instructions" class="min-h-28 font-mono" maxlength="4000" />
                        </label>
                        <label class="max-w-40">
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('ai.handoffThreshold') }}</span>
                            <Input v-model.number="form.handoff_threshold" type="number" min="1" max="100" />
                        </label>
                    </div>
                </div>

                <div class="rounded-xl border border-border bg-background/40 p-4">
                    <div class="mb-3 flex items-center gap-2">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary"><Cpu class="h-4 w-4" /></span>
                        <h3 class="text-sm font-semibold ui-text">Модель и каналы</h3>
                    </div>
                    <div class="flex flex-col gap-3">
                        <div>
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('ai.modelLabel') }}</span>
                            <Select v-model="form.model">
                                <SelectTrigger class="w-full"><SelectValue placeholder="Модель по умолчанию" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="model in MODEL_OPTIONS" :key="model" :value="model" :disabled="isModelLocked(model)">
                                        <span class="flex items-center gap-1.5">
                                            <Lock v-if="isModelLocked(model)" class="h-3 w-3 ui-subtle" />
                                            {{ model }}
                                        </span>
                                    </SelectItem>
                                    <SelectItem value="__custom__">{{ locale.t('ai.modelCustom') }}</SelectItem>
                                </SelectContent>
                            </Select>
                            <Input v-if="form.model === '__custom__'" v-model="form.modelCustom" class="mt-2" placeholder="Название модели" />
                            <p class="mt-1 text-[11px] ui-subtle">{{ locale.t('ai.modelHint') }}</p>
                            <p v-if="currentPlan.aiProviders.length < maxAiProviders" class="mt-1 text-[11px] text-amber-600 dark:text-amber-400">
                                На тарифе «{{ currentPlan.name }}» доступны: {{ currentPlan.aiProviders.join(', ') }}. Остальные модели — на старших тарифах.
                            </p>
                        </div>
                        <div>
                            <span class="mb-1 block text-xs font-semibold uppercase ui-subtle">{{ locale.t('ai.channelsLabel') }}</span>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="channel in CHANNEL_OPTIONS"
                                    :key="channel"
                                    type="button"
                                    class="rounded-full border px-3 py-1.5 text-xs font-semibold transition"
                                    :class="selectedChannels.includes(channel) ? CHANNEL_STYLES[channel] : 'border-border ui-subtle hover:bg-muted'"
                                    @click="toggleChannel(channel)"
                                >
                                    {{ sourceLabels[channel] ?? channel }}
                                </button>
                            </div>
                            <p class="mt-1 text-[11px] ui-subtle">{{ locale.t('ai.channelsHint') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-border bg-background/40 p-4">
                <div class="mb-3 flex items-center gap-2">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary"><BookOpen class="h-4 w-4" /></span>
                    <h3 class="text-sm font-semibold ui-text">База знаний</h3>
                </div>
                <div class="max-h-56 divide-y overflow-y-auto rounded-lg border border-border">
                    <label v-for="doc in allDocuments" :key="doc.id" class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm ui-text hover:bg-muted">
                        <input
                            type="checkbox"
                            class="size-4 accent-primary"
                            :checked="selectedDocIds.includes(doc.id)"
                            @change="toggleDoc(doc.id, ($event.target as HTMLInputElement).checked)"
                        >
                        <span class="min-w-0 flex-1 truncate">{{ doc.title }}</span>
                    </label>
                    <p v-if="! allDocuments.length" class="px-3 py-2 text-sm ui-subtle">Документы не найдены</p>
                </div>
            </div>
        </form>
    </div>
</template>
