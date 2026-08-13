<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { Bot, Info, Maximize2, MessagesSquare, Minimize2 } from '@lucide/vue';
import { Switch } from '../ui/switch';
import { Drawer, DrawerContent, DrawerHeader, DrawerTitle } from '../ui/drawer';
import { useCrmDashboardStore } from '../../stores/crmDashboard';
import { useLocaleStore } from '../../stores/locale';
import AiDraftPanel from './inbox/AiDraftPanel.vue';
import ChatThread from './inbox/ChatThread.vue';
import ConversationInfo from './inbox/ConversationInfo.vue';
import ConversationQueue from './inbox/ConversationQueue.vue';
import ReplyComposer from './inbox/ReplyComposer.vue';
import type { MessageAttachment } from '../../stores/crmDashboard';

const store = useCrmDashboardStore();
const locale = useLocaleStore();
const { conversations, integrationSettings, selectedConversation, selectedConversationId, selectedMessages } = storeToRefs(store);
const replyBody = ref('');
const activeTab = ref<'chat' | 'ai'>('chat');
const chatExpanded = ref(false);
const infoOpen = ref(false);

const aiDraft = computed(() => [...selectedMessages.value].reverse().find((message) => message.sender_type === 'ai') ?? null);
const autoReplyEnabled = computed(() => integrationSettings.value?.chatwoot.auto_reply_enabled ?? false);

onMounted(async () => {
    if (! integrationSettings.value) await store.loadIntegrationSettings();
});

async function toggleAutoReply(enabled: boolean): Promise<void> {
    await store.updateIntegrationSettings({ chatwoot: { auto_reply_enabled: enabled } });
}

async function sendReply(attachment?: MessageAttachment | null): Promise<void> {
    if (! selectedConversation.value || (! replyBody.value.trim() && ! attachment)) return;
    await store.replyToConversation(selectedConversation.value.id, replyBody.value.trim(), attachment);
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
    <section class="flex h-[calc(100vh-200px)] min-h-[620px] flex-col gap-4 overflow-hidden lg:flex-row">
        <aside data-tour="inbox-queue" class="conv-queue flex min-h-0 flex-col rounded-xl border border-border bg-card" :class="{ 'is-collapsed': chatExpanded }">
            <div class="w-[320px] max-w-full border-b px-4 py-3 border-border">
                <h2 class="font-display text-sm font-semibold ui-text">{{ locale.t('inbox.queueTitle') }}</h2>
                <p class="mt-0.5 text-xs ui-subtle">{{ conversations.length }} диалогов</p>
            </div>
            <div class="w-[320px] max-w-full min-h-0 flex-1 overflow-y-auto">
                <ConversationQueue :conversations="conversations" :selected-id="selectedConversationId" @select="store.selectConversation" />
            </div>
        </aside>

        <section class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden rounded-xl border border-border bg-card">
            <header class="flex shrink-0 items-center justify-between gap-3 border-b px-4 py-3 border-border">
                <div class="min-w-0">
                    <h2 class="truncate font-display text-base font-semibold ui-text">{{ selectedConversation?.customer?.name ?? locale.t('inbox.previewTitle') }}</h2>
                    <p class="mt-0.5 truncate text-xs ui-subtle">{{ selectedConversation?.subject }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <label data-tour="inbox-autoreply" class="inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-xs font-medium ui-text border-border">
                        <Switch :model-value="autoReplyEnabled" :disabled="store.busy" @update:model-value="toggleAutoReply" />
                        {{ locale.t('inbox.autoReply') }}
                    </label>
                    <div data-tour="inbox-tabs" class="flex rounded-lg border p-0.5 border-border">
                        <button v-for="t in (['chat', 'ai'] as const)" :key="t" class="inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-xs font-medium transition" :class="activeTab === t ? 'bg-muted ui-text' : 'ui-subtle'" type="button" @click="activeTab = t">
                            <component :is="t === 'chat' ? MessagesSquare : Bot" class="h-3.5 w-3.5" />{{ t === 'chat' ? locale.t('inbox.tabs.chat') : locale.t('inbox.tabs.ai') }}
                        </button>
                    </div>
                    <button
                        v-if="selectedConversation"
                        type="button"
                        data-tour="inbox-info"
                        class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border transition hover:bg-muted border-border"
                        :title="locale.t('inbox.previewTitle')"
                        @click="infoOpen = true"
                    >
                        <Info class="h-3.5 w-3.5 ui-subtle" />
                    </button>
                    <button
                        type="button"
                        data-tour="inbox-expand"
                        class="grid h-8 w-8 shrink-0 place-items-center rounded-lg border transition hover:bg-muted border-border"

                        :title="chatExpanded ? locale.t('inbox.collapseChat') : locale.t('inbox.expandChat')"
                        @click="chatExpanded = !chatExpanded"
                    >
                        <Minimize2 v-if="chatExpanded" class="h-3.5 w-3.5 ui-subtle" />
                        <Maximize2 v-else class="h-3.5 w-3.5 ui-subtle" />
                    </button>
                </div>
            </header>

            <template v-if="selectedConversation">
                <ChatThread v-if="activeTab === 'chat'" :messages="selectedMessages" />
                <div v-else class="min-h-0 flex-1 overflow-y-auto p-4">
                    <AiDraftPanel :draft="aiDraft" :summary="selectedConversation.ai_summary" :busy="store.busy" :can-send="Boolean(selectedConversation.external_id)" :can-generate="Boolean(selectedConversation.lead)" @generate-draft="generateDraft" @use-draft="insertDraft" @send-draft="sendDraft" />
                </div>

                <ReplyComposer v-model:body="replyBody" :busy="store.busy" :can-reply="Boolean(selectedConversation.external_id)" :conversation-id="selectedConversation.id" allow-attachments @send="sendReply" />
            </template>
        </section>

        <Drawer v-model:open="infoOpen" direction="right">
            <DrawerContent>
                <DrawerHeader>
                    <DrawerTitle>{{ locale.t('inbox.previewTitle') }}</DrawerTitle>
                </DrawerHeader>
                <div class="min-h-0 flex-1 overflow-y-auto">
                    <ConversationInfo v-if="selectedConversation" :conversation="selectedConversation" />
                </div>
            </DrawerContent>
        </Drawer>
    </section>
</template>

<style scoped>
.conv-queue {
    overflow: hidden;
    transition: width 300ms ease-in-out, opacity 300ms ease-in-out, margin 300ms ease-in-out, border-width 300ms ease-in-out;
}

@media (min-width: 1024px) {
    .conv-queue {
        width: 320px;
        flex-shrink: 0;
    }
    .conv-queue.is-collapsed {
        width: 0;
        opacity: 0;
        margin-right: -1rem;
        border-width: 0;
        pointer-events: none;
    }
}
</style>
