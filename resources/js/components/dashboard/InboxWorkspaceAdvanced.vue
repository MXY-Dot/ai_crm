<script lang="ts">
/** Module scope (shared across instances, survives remounts) — the plugin's provide() only needs to run once per app, not once per mount. */
let pluginInstalled = false;
</script>

<script setup lang="ts">
import { computed, getCurrentInstance, onBeforeUnmount, onMounted } from 'vue';
import { AdvancedChat, AdvancedChatPlugin, type ChatFileItem, type ChatModel } from '@advanced-chat/components';
import '@advanced-chat/components/styles';
import { useCrmDashboardStore } from '@/stores/crmDashboard';
import { useChatStore } from '@/stores/chat';
import { guessWeroType, toAdvancedChats, toAdvancedCurrentUser, toAdvancedMessages } from '@/lib/chat/advancedChatAdapter';

/**
 * Basic-pass chat UI on @advanced-chat/components, mounted only behind
 * VITE_ADVANCED_CHAT_ENABLED (see InboxPage.vue) — assign/resolve/labels/VIP/
 * AI-draft panel and the custom voice/video players from InboxWorkspace.vue
 * are deliberately not carried over here; this reuses the exact same
 * useChatStore() as the old UI, just with a different frontend on top.
 */

const dashboard = useCrmDashboardStore();
const chat = useChatStore();

// The library's localization override only exists via its plugin's app-level
// provide(); calling app.use() from here (not main.ts) keeps the whole package
// out of the main bundle — it only loads when this async component does.
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

onMounted(() => chat.init());
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

/** WERO sends at most one attachment per message — if the composer staged several, only the first goes out; the rest are silently dropped for this first pass (see plan). */
async function sendMessage(payload: { content: string; files: ChatFileItem[] }): Promise<void> {
    const conversationId = chat.activeConversationId;
    if (! conversationId) return;

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
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-border bg-card">
        <AdvancedChat
            :current-user="currentUser"
            :chats="chats"
            :chat="activeChat"
            :messages="messages"
            :loading-chats="chat.conversationsLoading"
            :chats-loaded="chat.conversationsMeta.current_page >= chat.conversationsMeta.last_page"
            :loading-messages="loadingMessages"
            :messages-loaded="messagesLoaded"
            height="calc(100vh - 200px)"
            theme="auto"
            @open-chat="openChat"
            @fetch-more-chats="fetchMoreChats"
            @search-chat="searchChat"
            @fetch-messages="fetchMessages"
            @send-message="sendMessage"
            @edit-message="editMessage"
            @typing-message="typingMessage"
        />
    </section>
</template>
