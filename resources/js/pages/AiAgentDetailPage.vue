<script setup lang="ts">
import { computed, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Trash2 } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AiAgentActivityPanel from '../components/dashboard/ai/AiAgentActivityPanel.vue';
import AiAgentSettingsForm from '../components/dashboard/ai/AiAgentSettingsForm.vue';
import { useCrmDashboardStore } from '../stores/crmDashboard';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '../components/ui/alert-dialog';
import { Button } from '../components/ui/button';

defineOptions({ layout: AppLayout });

const page = usePage<{ agentId: number }>();
const store = useCrmDashboardStore();
const { aiAgents, aiRuns, knowledgeDocuments, busy } = storeToRefs(store);

const agent = computed(() => aiAgents.value.find((a) => a.id === page.props.agentId) ?? null);
const agentDocuments = computed(() => knowledgeDocuments.value.filter((doc) => doc.ai_agent_id === agent.value?.id));

const deleteOpen = ref(false);

async function confirmDelete(): Promise<void> {
    if (! agent.value) return;
    await store.deleteAiAgent(agent.value.id);
    deleteOpen.value = false;
    router.visit('/ai');
}
</script>

<template>
    <section class="space-y-4">
        <Link href="/ai" class="inline-flex items-center gap-1.5 text-sm font-medium ui-subtle hover:text-primary">
            <ArrowLeft class="h-4 w-4" />Назад к списку
        </Link>

        <p v-if="! agent" class="text-sm ui-subtle">Ассистент не найден.</p>
        <template v-else>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-display text-2xl font-bold ui-text">{{ agent.name }}</h2>
                    <p class="mt-1 text-sm ui-subtle">{{ agent.provider }}<span v-if="agent.model"> · {{ agent.model }}</span></p>
                </div>
                <Button variant="destructive" size="sm" @click="deleteOpen = true"><Trash2 class="h-4 w-4" />Удалить ассистента</Button>
            </div>

            <AiAgentSettingsForm :agent="agent" :documents="agentDocuments" :all-documents="knowledgeDocuments" :busy="busy" />
            <AiAgentActivityPanel :agent="agent" :ai-runs="aiRuns" />
        </template>

        <AlertDialog v-model:open="deleteOpen">
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Удалить ассистента «{{ agent?.name }}»?</AlertDialogTitle>
                    <AlertDialogDescription>История его запусков тоже будет удалена без возможности восстановления.</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Отмена</AlertDialogCancel>
                    <AlertDialogAction :disabled="busy" @click="confirmDelete">Удалить</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </section>
</template>
