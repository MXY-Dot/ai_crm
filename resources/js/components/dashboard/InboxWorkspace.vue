<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { BotIcon } from '@lucide/vue';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useChatStore } from '../../stores/chat';
import { Drawer, DrawerContent, DrawerHeader, DrawerTitle } from '../ui/drawer';
import AiDraftPanel from './inbox/AiDraftPanel.vue';
import ConversationInfo from './inbox/ConversationInfo.vue';
import ChatComposer from './chat/ChatComposer.vue';
import ChatHeader from './chat/ChatHeader.vue';
import ChatMessages from './chat/ChatMessages.vue';
import ChatSidebar from './chat/ChatSidebar.vue';

const dashboard = useCrmDashboardStore();
const chat = useChatStore();
const { integrationSettings } = storeToRefs(dashboard);

const infoOpen = ref(false);
const aiPanelOpen = ref(false);
const composerRef = ref<InstanceType<typeof ChatComposer> | null>(null);

const activeConversation = computed(() => chat.activeConversation);

type AutoReplyMode = 'off' | 'priority' | 'always';
const AUTO_REPLY_MODES: AutoReplyMode[] = ['off', 'priority', 'always'];
const AUTO_REPLY_LABELS: Record<AutoReplyMode, string> = {
    off: 'Автоответ AI выключен',
    priority: 'Автоответ AI — приоритет у оператора',
    always: 'Автоответ AI — всегда',
};

const autoReplyMode = computed<AutoReplyMode>(() => integrationSettings.value?.chatwoot.auto_reply_mode ?? 'off');

// Sourced from the legacy tenant-wide bootstrap (same as before this rebuild) — AI-draft
// generation is a separate feature from the chat rebuild, only kept wired up as-is here.
const aiDraft = computed(() => {
    if (! activeConversation.value) return null;

    return [...dashboard.messages]
        .reverse()
        .find((message) => message.conversation_id === activeConversation.value?.id && message.sender_type === 'ai') ?? null;
});

onMounted(() => {
    chat.init();
    if (! integrationSettings.value) dashboard.loadIntegrationSettings();
});

onBeforeUnmount(() => chat.dispose());

async function cycleAutoReplyMode(): Promise<void> {
    const nextIndex = (AUTO_REPLY_MODES.indexOf(autoReplyMode.value) + 1) % AUTO_REPLY_MODES.length;
    await dashboard.updateIntegrationSettings({ chatwoot: { auto_reply_mode: AUTO_REPLY_MODES[nextIndex] } });
}

function useDraft(body: string): void {
    composerRef.value?.insertText(body);
    aiPanelOpen.value = false;
}

async function sendDraft(body: string): Promise<void> {
    if (! activeConversation.value || ! body.trim()) return;
    await chat.sendMessage(activeConversation.value.id, body.trim());
    aiPanelOpen.value = false;
}

async function generateDraft(): Promise<void> {
    if (! activeConversation.value) return;
    await dashboard.generateAiDraft(activeConversation.value.id);
    aiPanelOpen.value = true;
}

function goBackToList(): void {
    chat.unselectConversation();
}
</script>

<template>
    <section class="flex h-[calc(100vh-200px)] min-h-[620px] overflow-hidden rounded-xl border border-border bg-card">
        <div class="w-full shrink-0 lg:w-80" :class="activeConversation ? 'hidden lg:flex' : 'flex'">
            <ChatSidebar class="w-full" />
        </div>

        <div class="flex min-h-0 min-w-0 flex-1 flex-col" :class="activeConversation ? 'flex' : 'hidden lg:flex'">
            <template v-if="activeConversation">
                <ChatHeader @open-info="infoOpen = true" @open-sidebar="goBackToList" />

                <div class="flex items-center justify-between gap-3 border-b border-border px-3 py-2">
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-2 rounded-lg border px-2.5 text-xs font-medium ui-text border-border transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="dashboard.busy"
                        :title="AUTO_REPLY_LABELS[autoReplyMode]"
                        @click="cycleAutoReplyMode"
                    >
                        <span
                            class="relative inline-flex h-[18.4px] w-12 shrink-0 rounded-full border border-transparent transition-colors"
                            :class="{
                                'bg-input dark:bg-input/80': autoReplyMode === 'off',
                                'bg-amber-500': autoReplyMode === 'priority',
                                'bg-primary': autoReplyMode === 'always',
                            }"
                        >
                            <span
                                class="absolute top-1/2 h-4 w-4 -translate-y-1/2 rounded-full bg-background shadow-sm transition-all"
                                :class="{
                                    'left-0.5': autoReplyMode === 'off',
                                    'left-1/2 -translate-x-1/2': autoReplyMode === 'priority',
                                    'right-0.5': autoReplyMode === 'always',
                                }"
                            />
                        </span>
                        <span class="whitespace-nowrap">{{ AUTO_REPLY_LABELS[autoReplyMode] }}</span>
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-lg border px-2.5 text-xs font-medium transition hover:bg-muted border-border"
                        :class="aiPanelOpen ? 'bg-muted ui-text' : 'ui-subtle'"
                        @click="aiPanelOpen = ! aiPanelOpen"
                    >
                        <BotIcon class="h-3.5 w-3.5" />AI-черновик
                    </button>
                </div>

                <div v-if="aiPanelOpen" class="border-b border-border p-3">
                    <AiDraftPanel
                        :draft="aiDraft"
                        :summary="activeConversation.ai_summary"
                        :busy="dashboard.busy"
                        :can-send="Boolean(activeConversation.external_id)"
                        :can-generate="Boolean(activeConversation.lead)"
                        @generate-draft="generateDraft"
                        @use-draft="useDraft"
                        @send-draft="sendDraft"
                    />
                </div>

                <ChatMessages class="flex min-h-0 flex-1 flex-col" />
                <ChatComposer ref="composerRef" :conversation-id="activeConversation.id" :can-reply="Boolean(activeConversation.external_id)" />
            </template>

            <div v-else class="grid h-full place-items-center text-sm ui-subtle">Выберите диалог слева</div>
        </div>
    </section>

    <Drawer v-model:open="infoOpen" direction="right">
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>Информация о диалоге</DrawerTitle>
            </DrawerHeader>
            <div class="min-h-0 flex-1 overflow-y-auto">
                <ConversationInfo v-if="activeConversation" :conversation="activeConversation" />
            </div>
        </DrawerContent>
    </Drawer>
</template>
