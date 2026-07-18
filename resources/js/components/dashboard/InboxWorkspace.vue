<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { Bot, MessagesSquare, UserRound } from '@lucide/vue';
import { Switch } from '../ui/switch';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import AiDraftPanel from './inbox/AiDraftPanel.vue';
import ChatThread from './inbox/ChatThread.vue';
import ConversationInfo from './inbox/ConversationInfo.vue';
import ConversationQueue from './inbox/ConversationQueue.vue';
import ReplyComposer from './inbox/ReplyComposer.vue';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { conversations, integrationSettings, selectedConversation, selectedConversationId, selectedMessages } = storeToRefs(store);
const replyBody = ref('');
const activeTab = ref<'chat' | 'ai' | 'details'>('chat');

const aiDraft = computed(() => [...selectedMessages.value].reverse().find((message) => message.sender_type === 'ai') ?? null);
const autoReplyEnabled = computed(() => integrationSettings.value?.chatwoot.auto_reply_enabled ?? false);
const chatMessages = computed(() => selectedMessages.value);
const tabs = computed(() => [
    { id: 'chat', label: locale.t('inbox.tabs.chat'), icon: MessagesSquare },
    { id: 'ai', label: locale.t('inbox.tabs.ai'), icon: Bot },
    { id: 'details', label: locale.t('inbox.tabs.details'), icon: UserRound },
] as const);

onMounted(async () => {
    if (! integrationSettings.value) await store.loadIntegrationSettings();
});

async function toggleAutoReply(enabled: boolean): Promise<void> {
    await store.updateIntegrationSettings({
        chatwoot: { auto_reply_enabled: enabled },
    });
}

async function sendReply(): Promise<void> {
    if (! selectedConversation.value || ! replyBody.value.trim()) return;
    await store.replyToConversation(selectedConversation.value.id, replyBody.value.trim());
    replyBody.value = '';
}

function insertDraft(body: string): void {
    replyBody.value = body;
    activeTab.value = 'chat';
}

async function sendDraft(body: string): Promise<void> {
    if (! selectedConversation.value || ! body.trim()) return;
    await store.replyToConversation(selectedConversation.value.id, body.trim());
    replyBody.value = '';
    activeTab.value = 'chat';
}

async function generateDraft(): Promise<void> {
    if (! selectedConversation.value) return;
    await store.generateAiDraft(selectedConversation.value.id);
    activeTab.value = 'ai';
}
</script>

<template>
    <section class="grid h-[calc(100vh-250px)] min-h-[620px] gap-4 overflow-hidden xl:grid-cols-[380px_minmax(0,1fr)]">
        <aside class="flex min-h-0 flex-col overflow-hidden rounded-md border border-white/10 bg-zinc-950/40">
            <div class="border-b border-white/10 px-4 py-3">
                <h2 class="font-semibold text-white">{{ locale.t('inbox.queueTitle') }}</h2>
                <p class="mt-1 text-xs text-zinc-500">{{ conversations.length }} dialogs</p>
            </div>
            <ConversationQueue
                :conversations="conversations"
                :selected-id="selectedConversationId"
                @select="store.selectConversation"
            />
        </aside>

        <section class="flex min-h-0 flex-col overflow-hidden rounded-md border border-white/10 bg-zinc-950/40">
            <header class="shrink-0 border-b border-white/10 px-4 py-3">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="min-w-0">
                        <h2 class="truncate font-semibold text-white">{{ selectedConversation?.subject ?? locale.t('inbox.previewTitle') }}</h2>
                        <p class="mt-1 text-xs text-zinc-500">{{ selectedConversation?.customer?.name ?? locale.t('common.unknown') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <label class="inline-flex h-10 items-center gap-3 rounded-lg border border-border bg-card px-3 text-sm text-card-foreground shadow-sm">
                            <Switch :model-value="autoReplyEnabled" :disabled="store.busy" @update:model-value="toggleAutoReply" />
                            <span>{{ locale.t('inbox.autoReply') }}</span>
                            <span class="text-xs" :class="autoReplyEnabled ? 'text-emerald-500 dark:text-emerald-300' : 'text-muted-foreground'">{{ autoReplyEnabled ? locale.t('common.on') : locale.t('common.off') }}</span>
                        </label>
                        <div class="grid grid-cols-3 rounded-md border border-white/10 bg-white/[0.03] p-1 lg:w-[420px]">
                            <button
                                v-for="tab in tabs"
                                :key="tab.id"
                                class="inline-flex h-9 min-w-0 items-center justify-center gap-1 rounded px-2 text-xs font-medium transition sm:gap-2 sm:text-sm"
                                :class="activeTab === tab.id ? 'bg-white text-zinc-950' : 'text-zinc-400 hover:text-white'"
                                type="button"
                                @click="activeTab = tab.id"
                            >
                                <component :is="tab.icon" class="h-4 w-4" />
                                {{ tab.label }}
                            </button>
                        </div>
                    </div>
                </div>
            </header>

            <template v-if="selectedConversation">
                <ChatThread v-if="activeTab === 'chat'" :messages="chatMessages" />
                <div v-else-if="activeTab === 'ai'" class="min-h-0 flex-1 overflow-y-auto p-4">
                    <AiDraftPanel :draft="aiDraft" :summary="selectedConversation.ai_summary" :busy="store.busy" :can-send="Boolean(selectedConversation.external_id)" :can-generate="Boolean(selectedConversation.lead)" @generate-draft="generateDraft" @use-draft="insertDraft" @send-draft="sendDraft" />
                </div>
                <div v-else class="min-h-0 flex-1 overflow-y-auto p-4">
                    <ConversationInfo :conversation="selectedConversation" />
                </div>

                <ReplyComposer v-model:body="replyBody" :busy="store.busy" :can-reply="Boolean(selectedConversation.external_id)" @send="sendReply" />
            </template>
        </section>
    </section>
</template>
