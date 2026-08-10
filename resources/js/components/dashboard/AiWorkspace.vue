<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Bot, BrainCircuit, HelpCircle, Inbox, ListChecks, Workflow } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Card } from '../ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '../ui/tabs';
import AiAgentActivityPanel from './ai/AiAgentActivityPanel.vue';
import AiAgentList from './ai/AiAgentList.vue';
import AiAgentSettingsForm from './ai/AiAgentSettingsForm.vue';
import AiHandoffCenter from './AiHandoffCenter.vue';
import HelpAssistantPanel from './HelpAssistantPanel.vue';
import KnowledgeBasePanel from './KnowledgeBasePanel.vue';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { aiAgents, aiRuns, aiHandoffs, knowledgeDocuments, busy } = storeToRefs(store);
const activeTab = ref('agent');
const selectedAgentId = ref<number | null>(null);

watch(aiAgents, (agents) => {
    if (! selectedAgentId.value && agents[0]) selectedAgentId.value = agents[0].id;
}, { immediate: true });

const selectedAgent = computed(() => aiAgents.value.find((agent) => agent.id === selectedAgentId.value) ?? null);
const agentDocuments = computed(() => knowledgeDocuments.value.filter((doc) => doc.ai_agent_id === selectedAgentId.value));

const summary = computed(() => [
    { label: locale.t('ai.summary.agents'), value: aiAgents.value.length, icon: Bot },
    { label: locale.t('ai.summary.knowledge'), value: knowledgeDocuments.value.length, icon: BrainCircuit },
    { label: locale.t('ai.summary.handoffs'), value: aiHandoffs.value.length, icon: Inbox },
    { label: locale.t('ai.summary.runs'), value: aiRuns.value.length, icon: Workflow },
]);
const tabs = computed(() => [
    { value: 'agent', label: locale.t('ai.tabs.agent'), icon: Bot },
    { value: 'overview', label: locale.t('ai.tabs.overview'), icon: ListChecks },
    { value: 'knowledge', label: locale.t('ai.tabs.knowledge'), icon: BrainCircuit },
    { value: 'handoff', label: locale.t('ai.tabs.handoff'), icon: Inbox },
    { value: 'runs', label: locale.t('ai.tabs.runs'), icon: Workflow },
    { value: 'help', label: locale.t('ai.tabs.help'), icon: HelpCircle },
]);
</script>

<template>
    <Tabs v-model="activeTab" class="flex flex-col gap-5">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h2 class="font-display text-xl font-bold ui-text">{{ locale.t('ai.workspaceTitle') }}</h2>
                <p class="mt-1 text-sm ui-subtle">{{ locale.t('ai.workspaceSubtitle') }}</p>
            </div>
            <TabsList class="flex-wrap">
                <TabsTrigger v-for="tab in tabs" :key="tab.value" :value="tab.value">
                    <component :is="tab.icon" class="h-4 w-4" />
                    {{ tab.label }}
                </TabsTrigger>
            </TabsList>
        </div>

        <TabsContent value="agent" class="mt-0">
            <div class="grid gap-5 xl:grid-cols-[280px_minmax(0,1fr)_320px]">
                <AiAgentList :agents="aiAgents" :ai-runs="aiRuns" :selected-id="selectedAgentId" @select="selectedAgentId = $event" />
                <AiAgentSettingsForm :agent="selectedAgent" :documents="agentDocuments" :busy="busy" />
                <AiAgentActivityPanel :agent="selectedAgent" :ai-runs="aiRuns" />
            </div>
        </TabsContent>

        <TabsContent value="overview" class="mt-0">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <Card v-for="item in summary" :key="item.label" class="min-h-28">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm ui-subtle">{{ item.label }}</p>
                            <p class="mt-2 font-display text-3xl font-bold ui-text">{{ item.value }}</p>
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

        <TabsContent value="knowledge" class="mt-0">
            <KnowledgeBasePanel />
        </TabsContent>

        <TabsContent value="handoff" class="mt-0">
            <AiHandoffCenter />
        </TabsContent>

        <TabsContent value="runs" class="mt-0">
            <Card :title="locale.t('ai.runsTitle')" :subtitle="locale.t('ai.runsSubtitle')">
                <div class="grid gap-3">
                    <article v-for="run in aiRuns" :key="run.id" class="rounded-xl border p-4 border-border bg-muted">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-medium ui-text">{{ run.intent ?? locale.t('common.unknown') }}</p>
                                <p class="mt-1 text-xs ui-subtle">{{ run.conversation?.subject }}</p>
                            </div>
                            <span class="rounded px-2 py-0.5 text-xs font-semibold bg-card">{{ run.confidence }}%</span>
                        </div>
                        <p class="mt-3 text-sm leading-6 ui-subtle">{{ run.summary }}</p>
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
