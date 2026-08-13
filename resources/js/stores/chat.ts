import { computed, reactive, ref } from 'vue';
import { defineStore } from 'pinia';
import { toast } from 'vue-sonner';
import * as chatApi from '../lib/chat/api';
import { createChatRealtimeTransport, type ChatRealtimeTransport } from '../lib/chat/realtime';
import type { ChatConversation, ChatMessage, PendingAttachment, Typer } from '../lib/chat/types';
import { useCrmDashboardStore } from './crmDashboard';

/**
 * Chat business logic, isolated from both the UI (resources/js/components/dashboard/chat/*)
 * and the legacy whole-app `crmDashboard` store. This store owns: conversation
 * list + search + pagination, per-conversation message history + pagination,
 * optimistic send/edit/delete with reconciliation, typing presence, and unread
 * tracking. It talks to the backend only through `lib/chat/api.ts` and
 * `lib/chat/realtime.ts` — no `fetch`/`apiRequest` calls live here directly.
 */
export const useChatStore = defineStore('chat', () => {
    const dashboard = useCrmDashboardStore();
    const tenantSlug = (): string | null => dashboard.tenant?.slug ?? null;
    const tenantId = (): number | null => dashboard.tenant?.id ?? null;

    const conversations = ref<ChatConversation[]>([]);
    const conversationsMeta = ref({ current_page: 1, last_page: 1, total: 0 });
    const conversationsLoading = ref(false);
    const search = ref('');

    const activeConversationId = ref<number | null>(null);
    const messagesByConversation = reactive<Record<number, ChatMessage[]>>({});
    const messagesMeta = reactive<Record<number, { has_more: boolean; oldest_id: number | null }>>({});
    const messagesLoading = reactive<Record<number, boolean>>({});
    const loadingOlder = reactive<Record<number, boolean>>({});
    const typersByConversation = reactive<Record<number, Typer[]>>({});
    const aiGeneratingByConversation = reactive<Record<number, boolean>>({});
    const replyTarget = ref<ChatMessage | null>(null);

    const activeConversation = computed<ChatConversation | null>(
        () => conversations.value.find((c) => c.id === activeConversationId.value) ?? null,
    );
    const activeMessages = computed<ChatMessage[]>(() => (activeConversationId.value ? messagesByConversation[activeConversationId.value] ?? [] : []));
    const activeTypers = computed<Typer[]>(() => (activeConversationId.value ? typersByConversation[activeConversationId.value] ?? [] : []));
    const activeAiGenerating = computed<boolean>(() => (activeConversationId.value ? aiGeneratingByConversation[activeConversationId.value] ?? false : false));
    const totalUnread = computed(() => conversations.value.reduce((sum, c) => sum + c.unread_count, 0));

    let realtime: ChatRealtimeTransport | null = null;
    let conversationsPollTimer: number | null = null;
    const lastTypingSentAt: Record<number, number> = {};

    function ensureRealtime(): ChatRealtimeTransport {
        if (! realtime) {
            realtime = createChatRealtimeTransport(tenantSlug, tenantId);
            realtime.onMessage((conversationId, message) => applyIncomingMessage(conversationId, message));
            realtime.onConversationTouched((conversationId, message) => applyConversationTouched(conversationId, message));
            realtime.onTyping((conversationId, typers) => { typersByConversation[conversationId] = typers; });
            realtime.onAiGenerating((conversationId, generating) => { aiGeneratingByConversation[conversationId] = generating; });
        }

        return realtime;
    }

    function init(): void {
        ensureRealtime().start();
        loadConversations();

        if (conversationsPollTimer === null) {
            conversationsPollTimer = window.setInterval(() => {
                if (! document.hidden) loadConversations({ silent: true });
            }, 12000);
        }
    }

    function dispose(): void {
        realtime?.stop();
        if (conversationsPollTimer !== null) window.clearInterval(conversationsPollTimer);
        conversationsPollTimer = null;
    }

    async function loadConversations(opts: { page?: number; silent?: boolean } = {}): Promise<void> {
        const tenant = tenantSlug();
        if (! tenant) return;

        if (! opts.silent) conversationsLoading.value = true;
        try {
            const response = await chatApi.listConversations(tenant, { search: search.value || undefined, page: opts.page ?? 1 });
            conversations.value = response.data;
            conversationsMeta.value = { current_page: response.meta.current_page, last_page: response.meta.last_page, total: response.meta.total };
        } catch (error) {
            if (! opts.silent) toast.error(error instanceof Error ? error.message : 'Не удалось загрузить диалоги');
        } finally {
            if (! opts.silent) conversationsLoading.value = false;
        }
    }

    function setSearch(value: string): void {
        search.value = value;
        loadConversations();
    }

    async function loadConversationsPage(page: number): Promise<void> {
        await loadConversations({ page });
    }

    async function selectConversation(conversationId: number): Promise<void> {
        if (activeConversationId.value !== null && activeConversationId.value !== conversationId) {
            realtime?.unwatchConversation(activeConversationId.value);
        }
        activeConversationId.value = conversationId;

        if (! messagesByConversation[conversationId]) {
            await loadMessages(conversationId);
        }

        const lastId = messagesByConversation[conversationId]?.at(-1)?.id ?? 0;
        ensureRealtime().watchConversation(conversationId, lastId);

        markRead(conversationId);
    }

    function unselectConversation(): void {
        if (activeConversationId.value !== null) realtime?.unwatchConversation(activeConversationId.value);
        activeConversationId.value = null;
        replyTarget.value = null;
    }

    async function loadMessages(conversationId: number): Promise<void> {
        const tenant = tenantSlug();
        if (! tenant) return;

        messagesLoading[conversationId] = true;
        try {
            const page = await chatApi.getMessages(tenant, conversationId);
            messagesByConversation[conversationId] = page.data;
            messagesMeta[conversationId] = page.meta;
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Не удалось загрузить сообщения');
        } finally {
            messagesLoading[conversationId] = false;
        }
    }

    async function loadOlderMessages(conversationId: number): Promise<void> {
        const tenant = tenantSlug();
        const meta = messagesMeta[conversationId];
        if (! tenant || ! meta?.has_more || loadingOlder[conversationId]) return;

        loadingOlder[conversationId] = true;
        try {
            const page = await chatApi.getMessages(tenant, conversationId, { before: meta.oldest_id ?? undefined });
            messagesByConversation[conversationId] = [...page.data, ...(messagesByConversation[conversationId] ?? [])];
            messagesMeta[conversationId] = page.meta;
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Не удалось загрузить историю');
        } finally {
            loadingOlder[conversationId] = false;
        }
    }

    function applyIncomingMessage(conversationId: number, message: ChatMessage): void {
        const list = messagesByConversation[conversationId];
        if (! list) return;

        if (list.some((m) => m.id === message.id)) return;

        list.push(message);

        const conversation = conversations.value.find((c) => c.id === conversationId);
        if (conversation) {
            conversation.last_message_at = message.sent_at;
            if (conversationId !== activeConversationId.value && message.sender_type === 'customer') {
                conversation.unread_count += 1;
            }
        }

        if (conversationId === activeConversationId.value && message.sender_type === 'customer') {
            markRead(conversationId);
        }
    }

    /**
     * A message landed somewhere on this tenant that isn't the currently-open thread
     * (the open thread is handled by applyIncomingMessage above, via the same
     * MessageCreated broadcast). Keeps the sidebar's preview/order/unread fresh without
     * the old 12s poll — a brand-new conversation not yet in the list just triggers a
     * one-off silent refresh instead of trying to splice in a partial row.
     */
    function applyConversationTouched(conversationId: number, message: ChatMessage): void {
        if (conversationId === activeConversationId.value) return;

        const conversation = conversations.value.find((c) => c.id === conversationId);
        if (! conversation) {
            loadConversations({ silent: true });
            return;
        }

        conversation.last_message_at = message.sent_at;
        if (message.sender_type === 'customer') conversation.unread_count += 1;

        conversations.value.sort((a, b) => {
            if (a.is_pinned !== b.is_pinned) return a.is_pinned ? -1 : 1;
            return new Date(b.last_message_at ?? 0).getTime() - new Date(a.last_message_at ?? 0).getTime();
        });
    }

    async function sendMessage(conversationId: number, body: string, attachment?: PendingAttachment | null): Promise<void> {
        const tenant = tenantSlug();
        if (! tenant) return;

        const clientId = crypto.randomUUID();
        const optimistic: ChatMessage = {
            id: -Date.now(),
            conversation_id: conversationId,
            reply_to_message_id: replyTarget.value?.id ?? null,
            reply_to: replyTarget.value ? {
                id: replyTarget.value.id,
                sender_type: replyTarget.value.sender_type as ChatMessage['sender_type'],
                sender_name: replyTarget.value.sender_name,
                body: replyTarget.value.body,
                deleted_at: replyTarget.value.deleted_at,
            } : null,
            sender_type: 'operator',
            sender_name: dashboard.user?.name ?? 'Вы',
            body,
            sent_at: new Date().toISOString(),
            edited_at: null,
            deleted_at: null,
            meta: attachment ? { attachment } : null,
            clientId,
            status: 'sending',
        };

        (messagesByConversation[conversationId] ??= []).push(optimistic);
        const replyToId = replyTarget.value?.id ?? null;
        replyTarget.value = null;

        try {
            const response = await chatApi.sendMessage(tenant, conversationId, {
                body,
                reply_to_message_id: replyToId,
                attachment: attachment ? { url: attachment.url, path: attachment.path, type: attachment.type, filename: attachment.filename, mime: attachment.mime } : null,
            });
            reconcileSentMessage(conversationId, clientId, response.message);

            const conversation = conversations.value.find((c) => c.id === conversationId);
            if (conversation) conversation.last_message_at = response.conversation.last_message_at;
        } catch (error) {
            const list = messagesByConversation[conversationId];
            const failed = list?.find((m) => m.clientId === clientId);
            if (failed) failed.status = 'failed';
            toast.error(error instanceof Error ? error.message : 'Не удалось отправить сообщение');
        }
    }

    function reconcileSentMessage(conversationId: number, clientId: string, real: ChatMessage): void {
        const list = messagesByConversation[conversationId];
        if (! list) return;

        const index = list.findIndex((m) => m.clientId === clientId);
        if (index === -1) return;

        list.splice(index, 1, { ...real, status: 'sent' });
        ensureRealtime().setLastSeenMessageId(conversationId, real.id);
    }

    async function retrySend(conversationId: number, clientId: string): Promise<void> {
        const list = messagesByConversation[conversationId];
        const message = list?.find((m) => m.clientId === clientId);
        if (! message) return;

        list.splice(list.indexOf(message), 1);
        await sendMessage(conversationId, message.body, message.meta?.attachment as PendingAttachment | undefined);
    }

    function discardFailed(conversationId: number, clientId: string): void {
        const list = messagesByConversation[conversationId];
        if (! list) return;
        const index = list.findIndex((m) => m.clientId === clientId);
        if (index !== -1) list.splice(index, 1);
    }

    async function editMessage(conversationId: number, messageId: number, body: string): Promise<void> {
        const tenant = tenantSlug();
        if (! tenant) return;

        const list = messagesByConversation[conversationId];
        const message = list?.find((m) => m.id === messageId);
        const previousBody = message?.body;
        if (message) message.body = body;

        try {
            const response = await chatApi.editMessage(tenant, messageId, body);
            if (message) {
                message.body = response.message.body;
                message.edited_at = response.message.edited_at;
            }
        } catch (error) {
            if (message && previousBody !== undefined) message.body = previousBody;
            toast.error(error instanceof Error ? error.message : 'Не удалось изменить сообщение');
        }
    }

    async function deleteMessage(conversationId: number, messageId: number): Promise<void> {
        const tenant = tenantSlug();
        if (! tenant) return;

        const list = messagesByConversation[conversationId];
        const message = list?.find((m) => m.id === messageId);
        const wasDeleted = message?.deleted_at ?? null;
        if (message) message.deleted_at = new Date().toISOString();

        try {
            await chatApi.deleteMessage(tenant, messageId);
        } catch (error) {
            if (message) message.deleted_at = wasDeleted;
            toast.error(error instanceof Error ? error.message : 'Не удалось удалить сообщение');
        }
    }

    async function uploadAttachment(conversationId: number, file: File, type: PendingAttachment['type']): Promise<PendingAttachment | null> {
        const tenant = tenantSlug();
        if (! tenant) return null;

        try {
            return await chatApi.uploadAttachment(tenant, conversationId, file, type);
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Не удалось загрузить файл');
            return null;
        }
    }

    /**
     * Who's responsible for this customer — a shared "status" every operator sees
     * the same way, distinct from the personal pin below. Complements the automatic
     * "first reply claims it" behaviour on the backend (ConversationReplyController):
     * this is the explicit override, can take a conversation from another operator
     * or release your own. Optimistic like markRead; the periodic conversations poll
     * re-syncs the real value from the server either way if this call fails.
     */
    async function setAssignee(conversationId: number, claim: boolean): Promise<void> {
        const tenant = tenantSlug();
        const conversation = conversations.value.find((c) => c.id === conversationId);
        if (! tenant || ! conversation) return;

        const userId = claim ? (dashboard.user?.id ?? null) : null;
        const previous = { assigned_user_id: conversation.assigned_user_id, assigned_user: conversation.assigned_user };
        conversation.assigned_user_id = userId;
        conversation.assigned_user = claim && dashboard.user ? { id: dashboard.user.id, name: dashboard.user.name } : null;

        try {
            await chatApi.assignConversation(tenant, conversationId, userId);
        } catch (error) {
            conversation.assigned_user_id = previous.assigned_user_id;
            conversation.assigned_user = previous.assigned_user;
            toast.error(error instanceof Error ? error.message : 'Не удалось изменить закрепление');
        }
    }

    /**
     * Personal "pin to top of my own list" — like Telegram's chat pinning. Every
     * operator has their own independent pins on the same shared conversations;
     * this never touches `assigned_user`/`assigned_user_id` (see setAssignee above).
     */
    async function togglePin(conversationId: number): Promise<void> {
        const tenant = tenantSlug();
        const conversation = conversations.value.find((c) => c.id === conversationId);
        if (! tenant || ! conversation) return;

        const next = ! conversation.is_pinned;
        conversation.is_pinned = next;

        try {
            if (next) {
                await chatApi.pinConversation(tenant, conversationId);
            } else {
                await chatApi.unpinConversation(tenant, conversationId);
            }
        } catch (error) {
            conversation.is_pinned = ! next;
            toast.error(error instanceof Error ? error.message : 'Не удалось закрепить диалог');
        }
    }

    async function markRead(conversationId: number): Promise<void> {
        const tenant = tenantSlug();
        const conversation = conversations.value.find((c) => c.id === conversationId);
        if (! tenant || ! conversation || conversation.unread_count === 0) return;

        conversation.unread_count = 0;
        try {
            await chatApi.markConversationRead(tenant, conversationId);
        } catch {
            // Non-critical — the next conversations poll will re-sync the real count.
        }
    }

    function sendTyping(conversationId: number): void {
        const tenant = tenantSlug();
        if (! tenant) return;

        const now = Date.now();
        if (now - (lastTypingSentAt[conversationId] ?? 0) < 2000) return;
        lastTypingSentAt[conversationId] = now;

        chatApi.sendTypingHeartbeat(tenant, conversationId).catch(() => {});
    }

    function setReplyTarget(message: ChatMessage | null): void {
        replyTarget.value = message;
    }

    return {
        conversations,
        conversationsMeta,
        conversationsLoading,
        search,
        activeConversationId,
        activeConversation,
        activeMessages,
        activeTypers,
        activeAiGenerating,
        totalUnread,
        replyTarget,
        messagesLoading,
        loadingOlder,
        init,
        dispose,
        loadConversations,
        setSearch,
        loadConversationsPage,
        selectConversation,
        unselectConversation,
        loadOlderMessages,
        sendMessage,
        retrySend,
        discardFailed,
        editMessage,
        deleteMessage,
        uploadAttachment,
        markRead,
        setAssignee,
        togglePin,
        sendTyping,
        setReplyTarget,
    };
});
