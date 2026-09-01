<script setup lang="ts">
import { computed, ref } from 'vue';
import { Bot, BrainCircuit, Languages } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '../ui/tabs';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '../ui/tooltip';
import AiAgentList from './ai/AiAgentList.vue';
import LanguageExamplesPanel from './ai/LanguageExamplesPanel.vue';
import KnowledgeBasePanel from './KnowledgeBasePanel.vue';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { aiAgents, aiRuns, knowledgeDocuments, busy } = storeToRefs(store);
const activeTab = ref('agent');

const tabs = computed(() => [
    { value: 'agent', label: locale.t('ai.tabs.agent'), icon: Bot },
    { value: 'knowledge', label: locale.t('ai.tabs.knowledge'), icon: BrainCircuit },
    { value: 'examples', label: locale.t('languageExamples.title'), icon: Languages },
]);
</script>

<template>
    <Tabs v-model="activeTab" class="flex flex-col gap-5">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h2 class="font-display text-xl font-bold ui-text">{{ locale.t('ai.workspaceTitle') }}</h2>
                <p class="mt-1 text-sm ui-subtle">{{ locale.t('ai.workspaceSubtitle') }}</p>
            </div>
            <TooltipProvider :delay-duration="200">
                <TabsList class="flex-wrap" data-tour="ai-tabs">
                    <Tooltip v-for="tab in tabs" :key="tab.value">
                        <TooltipTrigger as-child>
                            <TabsTrigger :value="tab.value" :aria-label="tab.label">
                                <component :is="tab.icon" class="h-4 w-4" />
                            </TabsTrigger>
                        </TooltipTrigger>
                        <TooltipContent>{{ tab.label }}</TooltipContent>
                    </Tooltip>
                </TabsList>
            </TooltipProvider>
        </div>

        <TabsContent value="agent" class="mt-0" data-tour="ai-agent-columns">
            <AiAgentList :agents="aiAgents" :ai-runs="aiRuns" :knowledge-documents="knowledgeDocuments" :busy="busy" />
        </TabsContent>

        <TabsContent value="knowledge" class="mt-0">
            <KnowledgeBasePanel />
        </TabsContent>

        <TabsContent value="examples" class="mt-0">
            <p class="mb-4 text-sm ui-subtle">{{ locale.t('languageExamples.subtitle') }}</p>
            <LanguageExamplesPanel />
        </TabsContent>
    </Tabs>
</template>
