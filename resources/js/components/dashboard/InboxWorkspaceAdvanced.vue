<script lang="ts">
/** Module scope (shared across instances, survives remounts) — the plugin's provide() only needs to run once per app, not once per mount. */
let pluginInstalled = false;
</script>

<script setup lang="ts">
import { computed, getCurrentInstance, onBeforeUnmount, onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { toast } from 'vue-sonner';
import { BotIcon, XIcon } from '@lucide/vue';
import {
    AdvancedChat,
    AdvancedChatPlugin,
    EDIT_ACTION,
    REPLY_ACTION,
    type Action,
    type ChatFileItem,
    type ChatModel,
    type MessageModel,
} from '@advanced-chat/components';
import '@advanced-chat/components/styles';
import '../../../css/advanced-chat-theme.css';
import { useCrmDashboardStore } from '@/stores/crmDashboard';
import { useChatStore } from '@/stores/chat';
import { guessWeroType, toAdvancedChats, toAdvancedCurrentUser, toAdvancedMessages } from '@/lib/chat/advancedChatAdapter';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Drawer, DrawerContent, DrawerHeader, DrawerTitle } from '@/components/ui/drawer';
import { Input } from '@/components/ui/input';
import AdvancedVoiceRecorder from './chat/AdvancedVoiceRecorder.vue';
import AiDraftPanel from './inbox/AiDraftPanel.vue';
import ConversationInfo from './inbox/ConversationInfo.vue';

/**
 * Full-parity pass on @advanced-chat/components, mounted only behind
 * VITE_ADVANCED_CHAT_ENABLED (see InboxPage.vue). <AdvancedChat> has no
 * slots (only props/events — verified against dist/index.d.ts), so anything
 * that needs a slot lives outside it: the AI-draft/auto-reply toolbar sits
 * above the widget, the voice recorder is an overlay button, VIP/channel/
 * health info is one click away in the reused ConversationInfo drawer.
 */

const dashboard = useCrmDashboardStore();
const chat = useChatStore();
const { integrationSettings } = storeToRefs(dashboard);

const RU_STRINGS = {
    'chats.empty': 'Диалоги не найдены',
    'chats.search.placeholder': 'Поиск диалогов...',
    'chat.empty': 'Выберите диалог слева',
    'chat.messages.empty': 'Нет сообщений',
    'chat.messages.new': 'Новые сообщения',
    'chat.message.placeholder': 'Написать сообщение...',
    'chat.message.deleted': 'Сообщение удалено',
    'chat.message.failure': 'Не удалось отправить',
    'chat.typing': 'печатает...',
    'chat.cancel-selection': 'Отменить выбор',
    'chat.cancel-reply': 'Отменить ответ',
    'chat.cancel-edit': 'Отменить редактирование',
    'chat.scroll-to-bottom': 'Прокрутить вниз',
    'chat.user.is-online': 'В сети',
    'chat.user.last-seen': 'Был(а) в сети',
    'chat.autocomplete.emojis': 'Эмодзи',
    'chat.autocomplete.users': 'Пользователи',
    'chat.state.loading': 'Загрузка...',
    'chat.state.empty': 'Пусто',
    'chat.state.error': 'Ошибка',
    'chat.state.offline': 'Нет соединения',
    'chat.state.reconnecting': 'Переподключение...',
    'chat.state.permission-denied': 'Доступ запрещён',
    'chat.state.retry': 'Повторить',
};

const instance = getCurrentInstance();
if (! pluginInstalled && instance) {
    instance.appContext.app.use(AdvancedChatPlugin({ strings: RU_STRINGS }));
    pluginInstalled = true;
}

const currentUser = computed(() => toAdvancedCurrentUser(dashboard.user));
const chats = computed<ChatModel[]>(() => toAdvancedChats(chat.conversations, chat.activeConversationId, chat.activeTypers));
const activeChat = computed<ChatModel | null>(() => chats.value.find((c) => c.id === String(chat.activeConversationId)) ?? null);
const messages = computed(() => toAdvancedMessages(chat.activeMessages));
const loadingMessages = computed(() => (chat.activeConversationId ? Boolean(chat.messagesLoading[chat.activeConversationId]) : false));
const messagesLoaded = computed(() => (chat.activeConversationId ? ! (chat.messagesMeta[chat.activeConversationId]?.has_more ?? true) : false));

onMounted(() => {
    chat.init();
    if (! integrationSettings.value) dashboard.loadIntegrationSettings();
});
onBeforeUnmount(() => chat.dispose());

function openChat(model: ChatModel): void {
    void chat.selectConversation(Number(model.id));
}

function fetchMoreChats(): void {
    void chat.loadConversationsPage(chat.conversationsMeta.current_page + 1);
}

function searchChat(query: string): void {
    chat.setSearch(query);
}

function fetchMessages(): void {
    if (chat.activeConversationId) void chat.loadOlderMessages(chat.activeConversationId);
}

/** WERO sends at most one attachment per message — if the composer staged several, only the first goes out. */
async function sendMessage(payload: { content: string; files: ChatFileItem[]; reply?: MessageModel | null }): Promise<void> {
    const conversationId = chat.activeConversationId;
    if (! conversationId) return;

    if (payload.reply) {
        const original = chat.activeMessages.find((m) => m.id === Number(payload.reply?.id));
        if (original) chat.setReplyTarget(original);
    }

    const [file] = payload.files;
    let attachment = null;

    if (file?.blob) {
        const realFile = new File([file.blob], file.name, { type: file.type });
        attachment = await chat.uploadAttachment(conversationId, realFile, guessWeroType(file.type));
    }

    await chat.sendMessage(conversationId, payload.content, attachment);
}

function editMessage(payload: { messageId: string; content: string }): void {
    const conversationId = chat.activeConversationId;
    if (! conversationId) return;

    void chat.editMessage(conversationId, Number(payload.messageId), payload.content);
}

function typingMessage(): void {
    if (chat.activeConversationId) chat.sendTyping(chat.activeConversationId);
}

// --- assign / resolve / pin / labels — Action is a static list (doesn't know
// per-row state), so every item is always shown; handlers read live state at
// click time instead of the menu itself changing shape (see plan). ---
const conversationActions: Action[] = [
    { id: 'claim', label: 'Взять в работу' },
    { id: 'release', label: 'Вернуть AI' },
    { id: 'resolve', label: 'Закрыть диалог' },
    { id: 'toggle_pin', label: 'Закрепить / открепить' },
    { id: 'edit_labels', label: 'Лейблы' },
];

function handleConversationAction(payload: { chat: ChatModel; action: Action }): void {
    const conversationId = Number(payload.chat.id);
    const conversation = chat.conversations.find((c) => c.id === conversationId);
    if (! conversation) return;

    switch (payload.action.id) {
        case 'claim':
            void chat.setAssignee(conversationId, true);
            break;
        case 'release':
            void chat.setAssignee(conversationId, false);
            break;
        case 'resolve':
            if (conversation.status === 'closed') { toast.info('Диалог уже закрыт'); break; }
            void chat.resolveConversation(conversationId);
            break;
        case 'toggle_pin':
            void chat.togglePin(conversationId);
            break;
        case 'edit_labels':
            openLabelsDialog(conversationId);
            break;
    }
}

// --- reply/edit/delete on messages ---
const messageActions: Action[] = [
    { id: REPLY_ACTION, label: 'Ответить' },
    { id: EDIT_ACTION, label: 'Редактировать', ownMessageOnly: true },
    { id: 'delete', label: 'Удалить', ownMessageOnly: true },
];

function handleMessageAction(payload: { action: Action; message: MessageModel }): void {
    if (payload.action.id !== 'delete') return; // reply/edit are handled natively by the library (useReplyEdit)
    const conversationId = chat.activeConversationId;
    if (! conversationId) return;

    void chat.deleteMessage(conversationId, Number(payload.message.id));
}

// --- labels dialog (Этап 3 conversation labels — no popover slot to reuse here, so a small Dialog instead) ---
const labelsDialogOpen = ref(false);
const labelsDialogConversationId = ref<number | null>(null);
const labelsDraft = ref<string[]>([]);
const newLabelInput = ref('');

function openLabelsDialog(conversationId: number): void {
    const conversation = chat.conversations.find((c) => c.id === conversationId);
    labelsDialogConversationId.value = conversationId;
    labelsDraft.value = [...(conversation?.labels ?? [])];
    labelsDialogOpen.value = true;
}

function addDraftLabel(): void {
    const value = newLabelInput.value.trim();
    if (! value || labelsDraft.value.includes(value)) return;
    labelsDraft.value.push(value);
    newLabelInput.value = '';
}

function removeDraftLabel(value: string): void {
    labelsDraft.value = labelsDraft.value.filter((l) => l !== value);
}

async function saveLabels(): Promise<void> {
    if (labelsDialogConversationId.value === null) return;
    await chat.setLabels(labelsDialogConversationId.value, labelsDraft.value);
    labelsDialogOpen.value = false;
}

// --- info drawer (VIP/channel/customer details — reuses the old UI's own component as-is) ---
const infoOpen = ref(false);

function showChatInfo(): void {
    infoOpen.value = true;
}

// --- AI-draft panel + auto-reply toggle (same logic as InboxWorkspace.vue) ---
const aiPanelOpen = ref(false);
type AutoReplyMode = 'off' | 'priority' | 'always';
const AUTO_REPLY_MODES: AutoReplyMode[] = ['off', 'priority', 'always'];
const AUTO_REPLY_LABELS: Record<AutoReplyMode, string> = {
    off: 'Автоответ AI выключен',
    priority: 'Автоответ AI — приоритет у оператора',
    always: 'Автоответ AI — всегда',
};
const autoReplyMode = computed<AutoReplyMode>(() => integrationSettings.value?.chatwoot.auto_reply_mode ?? 'off');

const aiDraft = computed(() => {
    if (! chat.activeConversation) return null;

    return [...dashboard.messages]
        .reverse()
        .find((message) => message.conversation_id === chat.activeConversation?.id && message.sender_type === 'ai') ?? null;
});

async function cycleAutoReplyMode(): Promise<void> {
    const nextIndex = (AUTO_REPLY_MODES.indexOf(autoReplyMode.value) + 1) % AUTO_REPLY_MODES.length;
    await dashboard.updateIntegrationSettings({ chatwoot: { auto_reply_mode: AUTO_REPLY_MODES[nextIndex] } });
}

/** No public API to preset the library's composer text (no slot, no v-model on the input) — copy to clipboard is the closest honest equivalent to the old "insert into composer" flow. */
async function useDraft(body: string): Promise<void> {
    await navigator.clipboard.writeText(body);
    toast.info('Черновик скопирован — вставьте вручную');
    aiPanelOpen.value = false;
}

async function sendDraft(body: string): Promise<void> {
    if (! chat.activeConversation || ! body.trim()) return;
    await chat.sendMessage(chat.activeConversation.id, body.trim());
    aiPanelOpen.value = false;
}

async function generateDraft(): Promise<void> {
    if (! chat.activeConversation) return;
    await dashboard.generateAiDraft(chat.activeConversation.id);
    aiPanelOpen.value = true;
}
</script>

<template>
    <section class="relative overflow-hidden rounded-xl border border-border bg-card">
        <div v-if="chat.activeConversation" class="flex items-center justify-between gap-3 border-b border-border px-3 py-2">
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

        <div v-if="aiPanelOpen && chat.activeConversation" class="border-b border-border p-3">
            <AiDraftPanel
                :draft="aiDraft"
                :summary="chat.activeConversation.ai_summary"
                :busy="dashboard.busy"
                :can-send="Boolean(chat.activeConversation.external_id)"
                :can-generate="Boolean(chat.activeConversation.lead)"
                @generate-draft="generateDraft"
                @use-draft="useDraft"
                @send-draft="sendDraft"
            />
        </div>

        <div class="advanced-chat-root relative">
            <AdvancedChat
                :current-user="currentUser"
                :chats="chats"
                :chat="activeChat"
                :messages="messages"
                :loading-chats="chat.conversationsLoading"
                :chats-loaded="chat.conversationsMeta.current_page >= chat.conversationsMeta.last_page"
                :loading-messages="loadingMessages"
                :messages-loaded="messagesLoaded"
                :chat-actions="conversationActions"
                :header-actions="conversationActions"
                :message-actions="messageActions"
                :chat-info-enabled="true"
                height="calc(100vh - 260px)"
                theme="auto"
                @open-chat="openChat"
                @fetch-more-chats="fetchMoreChats"
                @search-chat="searchChat"
                @fetch-messages="fetchMessages"
                @send-message="sendMessage"
                @edit-message="editMessage"
                @typing-message="typingMessage"
                @chat-action-handler="handleConversationAction"
                @menu-action-handler="handleConversationAction"
                @message-action-handler="handleMessageAction"
                @show-chat-info="showChatInfo"
            />
            <AdvancedVoiceRecorder v-if="chat.activeConversationId" :conversation-id="chat.activeConversationId" />
        </div>
    </section>

    <Drawer v-model:open="infoOpen" direction="right">
        <DrawerContent>
            <DrawerHeader>
                <DrawerTitle>Информация о диалоге</DrawerTitle>
            </DrawerHeader>
            <div class="min-h-0 flex-1 overflow-y-auto">
                <ConversationInfo v-if="chat.activeConversation" :conversation="chat.activeConversation" />
            </div>
        </DrawerContent>
    </Drawer>

    <Dialog v-model:open="labelsDialogOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Лейблы диалога</DialogTitle>
            </DialogHeader>
            <div class="flex flex-wrap gap-1.5">
                <Badge v-for="l in labelsDraft" :key="l" class="gap-1">
                    {{ l }}
                    <button type="button" class="opacity-60 hover:opacity-100" @click="removeDraftLabel(l)"><XIcon class="h-3 w-3" /></button>
                </Badge>
            </div>
            <Input v-model="newLabelInput" placeholder="Например: доставка" @keyup.enter="addDraftLabel" />
            <DialogFooter>
                <Button variant="outline" @click="labelsDialogOpen = false">Отмена</Button>
                <Button @click="saveLabels">Сохранить</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
