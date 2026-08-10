<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { Bot, MessagesSquare } from '@lucide/vue';
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
const activeTab = ref<'chat' | 'ai'>('chat');

const aiDraft = computed(() => [...selectedMessages.value].reverse().find((message) => message.sender_type === 'ai') ?? null);
const autoReplyEnabled = computed(() => integrationSettings.value?.chatwoot.auto_reply_enabled ?? false);

onMounted(async () => {
    if (! integrationSettings.value) await store.loadIntegrationSettings();
});

async function toggleAutoReply(enabled: boolean): Promise<void> {
    await store.updateIntegrationSettings({ chatwoot: { auto_reply_enabled: enabled } });
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
    <section class="grid h-[calc(100vh-200px)] min-h-[620px] gap-4 overflow-hidden lg:grid-cols-[320px_minmax(0,1fr)] xl:grid-cols-[320px_minmax(0,1fr)_300px]">
        <aside class="flex min-h-0 flex-col overflow-hidden rounded-xl border" style="border-color: var(--border); background: var(--card)">
            <div class="border-b px-4 py-3" style="border-color: var(--border)">
                <h2 class="font-display text-sm font-semibold ui-text">{{ locale.t('inbox.queueTitle') }}</h2>
                <p class="mt-0.5 text-xs ui-subtle">{{ conversations.length }} диалогов</p>
            </div>
            <ConversationQueue :conversations="conversations" :selected-id="selectedConversationId" @select="store.selectConversation" />
        </aside>

        <section class="flex min-h-0 flex-col overflow-hidden rounded-xl border" style="border-color: var(--border); background: var(--card)">
            <header class="flex shrink-0 items-center justify-between gap-3 border-b px-4 py-3" style="border-color: var(--border)">
                <div class="min-w-0">
                    <h2 class="truncate font-display text-base font-semibold ui-text">{{ selectedConversation?.customer?.name ?? locale.t('inbox.previewTitle') }}</h2>
                    <p class="mt-0.5 truncate text-xs ui-subtle">{{ selectedConversation?.subject }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <label class="inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-xs font-medium ui-text" style="border-color: var(--border)">
                        <Switch :model-value="autoReplyEnabled" :disabled="store.busy" @update:model-value="toggleAutoReply" />
                        {{ locale.t('inbox.autoReply') }}
                    </label>
                    <div class="flex rounded-lg border p-0.5" style="border-color: var(--border)">
                        <button v-for="t in (['chat', 'ai'] as const)" :key="t" class="inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-xs font-medium transition" :class="activeTab === t ? 'bg-muted ui-text' : 'ui-subtle'" type="button" @click="activeTab = t">
                            <component :is="t === 'chat' ? MessagesSquare : Bot" class="h-3.5 w-3.5" />{{ t === 'chat' ? locale.t('inbox.tabs.chat') : locale.t('inbox.tabs.ai') }}
                        </button>
                    </div>
                </div>
            </header>

            <template v-if="selectedConversation">
                <ChatThread v-if="activeTab === 'chat'" :messages="selectedMessages" />
                <div v-else class="min-h-0 flex-1 overflow-y-auto p-4">
                    <AiDraftPanel :draft="aiDraft" :summary="selectedConversation.ai_summary" :busy="store.busy" :can-send="Boolean(selectedConversation.external_id)" :can-generate="Boolean(selectedConversation.lead)" @generate-draft="generateDraft" @use-draft="insertDraft" @send-draft="sendDraft" />
                </div>

                <ReplyComposer v-model:body="replyBody" :busy="store.busy" :can-reply="Boolean(selectedConversation.external_id)" @send="sendReply" />
            </template>
        </section>

        <aside v-if="selectedConversation" class="hidden min-h-0 flex-col overflow-hidden rounded-xl border xl:flex" style="border-color: var(--border); background: var(--card)">
            <ConversationInfo :conversation="selectedConversation" />
        </aside>
    </section>
</template>
