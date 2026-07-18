<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Bot, BrainCircuit, HelpCircle, Inbox, ListChecks, Save, Workflow } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { useCrmDashboardStore, type AiAgent } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Badge } from '../ui/badge';
import { Button } from '../ui/button';
import { Card } from '../ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '../ui/tabs';
import AiHandoffCenter from './AiHandoffCenter.vue';
import CompanyProfilePanel from './CompanyProfilePanel.vue';
import HelpAssistantPanel from './HelpAssistantPanel.vue';
import KnowledgeBasePanel from './KnowledgeBasePanel.vue';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { aiAgents, aiRuns, aiHandoffs, knowledgeDocuments, busy } = storeToRefs(store);
const activeTab = ref('overview');

const forms = reactive<Record<number, { name: string; status: 'active' | 'paused' | 'disabled'; handoff_threshold: number; instructions: string }>>({});
const summary = computed(() => [
    { label: locale.t('ai.summary.agents'), value: aiAgents.value.length, icon: Bot },
    { label: locale.t('ai.summary.knowledge'), value: knowledgeDocuments.value.length, icon: BrainCircuit },
    { label: locale.t('ai.summary.handoffs'), value: aiHandoffs.value.length, icon: Inbox },
    { label: locale.t('ai.summary.runs'), value: aiRuns.value.length, icon: Workflow },
]);
const tabs = computed(() => [
    { value: 'overview', label: locale.t('ai.tabs.overview'), icon: ListChecks },
    { value: 'agent', label: locale.t('ai.tabs.agent'), icon: Bot },
    { value: 'knowledge', label: locale.t('ai.tabs.knowledge'), icon: BrainCircuit },
    { value: 'handoff', label: locale.t('ai.tabs.handoff'), icon: Inbox },
    { value: 'runs', label: locale.t('ai.tabs.runs'), icon: Workflow },
    { value: 'help', label: locale.t('ai.tabs.help'), icon: HelpCircle },
]);

watch(aiAgents, (agents) => {
    agents.forEach((agent) => syncForm(agent));
}, { immediate: true });

function syncForm(agent: AiAgent): void {
    if (forms[agent.id]) return;

    forms[agent.id] = {
        name: agent.name,
        status: ['active', 'paused', 'disabled'].includes(agent.status) ? agent.status as 'active' | 'paused' | 'disabled' : 'active',
        handoff_threshold: agent.handoff_threshold,
        instructions: agent.instructions ?? '',
    };
}

async function saveAgent(agent: AiAgent): Promise<void> {
    const form = forms[agent.id];
    if (! form) return;

    await store.updateAiAgent(agent.id, {
        name: form.name.trim(),
        status: form.status,
        handoff_threshold: Number(form.handoff_threshold),
        instructions: form.instructions.trim(),
    });
}
</script>

<template>
    <Tabs v-model="activeTab" class="flex flex-col gap-5">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-foreground">{{ locale.t('ai.workspaceTitle') }}</h2>
                <p class="mt-1 text-sm text-muted-foreground">{{ locale.t('ai.workspaceSubtitle') }}</p>
            </div>
            <TabsList class="flex-wrap">
                <TabsTrigger v-for="tab in tabs" :key="tab.value" :value="tab.value">
                    <component :is="tab.icon" class="h-4 w-4" />
                    {{ tab.label }}
                </TabsTrigger>
            </TabsList>
        </div>

        <TabsContent value="overview" class="mt-0">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <Card v-for="item in summary" :key="item.label" class="min-h-28">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm text-muted-foreground">{{ item.label }}</p>
                            <p class="mt-2 text-3xl font-semibold text-foreground">{{ item.value }}</p>
                        </div>
                        <div class="grid h-10 w-10 place-items-center rounded-lg bg-muted text-primary">
                            <component :is="item.icon" class="h-5 w-5" />
                        </div>
                    </div>
                </Card>
            </div>
            <div class="mt-5 grid gap-5 xl:grid-cols-[0.9fr_1.1fr]">
                <HelpAssistantPanel />
                <AiHandoffCenter />
            </div>
        </TabsContent>

        <TabsContent value="agent" class="mt-0">
            <Card :title="locale.t('ai.profileTitle')" :subtitle="locale.t('ai.profileSubtitle')">
                <div class="grid gap-4">
                    <form v-for="agent in aiAgents" :key="agent.id" class="grid gap-4 rounded-xl border border-border bg-muted/30 p-4" @submit.prevent="saveAgent(agent)">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="flex items-center gap-2 font-medium text-foreground"><Bot class="h-4 w-4 text-primary" />{{ agent.name }}</p>
                                <p class="mt-1 text-xs uppercase text-muted-foreground">{{ agent.provider }}</p>
                            </div>
                            <Badge :tone="agent.status === 'active' ? 'green' : 'amber'">{{ locale.t('ai.status.' + agent.status) }}</Badge>
                        </div>

                        <div v-if="forms[agent.id]" class="grid gap-3 sm:grid-cols-[1fr_10rem]">
                            <label>
                                <span class="mb-1 block text-xs uppercase text-muted-foreground">{{ locale.t('ai.agentName') }}</span>
                                <input v-model="forms[agent.id].name" class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm text-foreground outline-none focus:border-ring" maxlength="120" required>
                            </label>
                            <label>
                                <span class="mb-1 block text-xs uppercase text-muted-foreground">{{ locale.t('ai.agentStatus') }}</span>
                                <select v-model="forms[agent.id].status" class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm text-foreground outline-none focus:border-ring">
                                    <option value="active">{{ locale.t('ai.status.active') }}</option>
                                    <option value="paused">{{ locale.t('ai.status.paused') }}</option>
                                    <option value="disabled">{{ locale.t('ai.status.disabled') }}</option>
                                </select>
                            </label>
                        </div>

                        <label v-if="forms[agent.id]" class="block">
                            <span class="mb-1 block text-xs uppercase text-muted-foreground">{{ locale.t('ai.instructions') }}</span>
                            <textarea v-model="forms[agent.id].instructions" class="min-h-32 w-full resize-y rounded-lg border border-input bg-background px-3 py-2 text-sm leading-6 text-foreground outline-none focus:border-ring" maxlength="4000" />
                        </label>

                        <div v-if="forms[agent.id]" class="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                            <label>
                                <span class="mb-1 block text-xs uppercase text-muted-foreground">{{ locale.t('ai.handoffThreshold') }}</span>
                                <input v-model.number="forms[agent.id].handoff_threshold" type="number" min="1" max="100" class="h-10 w-full rounded-lg border border-input bg-background px-3 text-sm text-foreground outline-none focus:border-ring">
                            </label>
                            <Button variant="primary" type="submit" :disabled="busy">
                                <Save class="h-4 w-4" />
                                {{ busy ? locale.t('common.waiting') : locale.t('ai.saveAgent') }}
                            </Button>
                        </div>
                    </form>
                </div>
            </Card>
        </TabsContent>

        <TabsContent value="knowledge" class="mt-0">
            <div class="grid gap-5 xl:grid-cols-[0.9fr_1.1fr]">
                <CompanyProfilePanel />
                <KnowledgeBasePanel />
            </div>
        </TabsContent>

        <TabsContent value="handoff" class="mt-0">
            <AiHandoffCenter />
        </TabsContent>

        <TabsContent value="runs" class="mt-0">
            <Card :title="locale.t('ai.runsTitle')" :subtitle="locale.t('ai.runsSubtitle')">
                <div class="grid gap-3">
                    <article v-for="run in aiRuns" :key="run.id" class="rounded-xl border border-border bg-muted/30 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-medium text-foreground">{{ run.intent ?? locale.t('common.unknown') }}</p>
                                <p class="mt-1 text-xs text-muted-foreground">{{ run.conversation?.subject }}</p>
                            </div>
                            <Badge :tone="run.confidence >= 70 ? 'green' : 'amber'">{{ run.confidence }}%</Badge>
                        </div>
                        <p class="mt-3 text-sm leading-6 text-muted-foreground">{{ run.summary }}</p>
                        <p class="mt-3 flex items-center gap-2 text-sm text-primary"><Workflow class="h-4 w-4" />{{ run.next_action }}</p>
                    </article>
                </div>
            </Card>
        </TabsContent>

        <TabsContent value="help" class="mt-0">
            <HelpAssistantPanel />
        </TabsContent>
    </Tabs>
</template>