import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { apiRequest } from '../lib/apiClient';
import { useCrmDashboardStore } from './crmDashboard';

export type TeamThreadUser = { id: number; name: string; email: string; phone: string | null; role: string; avatar_url: string | null; last_seen_at: string | null };

export type TeamThread = {
    user: TeamThreadUser;
    last_message: string | null;
    last_message_at: string | null;
    unread_count: number;
};

export type TeamMessageAttachment = {
    url: string;
    path: string;
    type: 'photo' | 'voice' | 'document';
    filename?: string | null;
    mime?: string | null;
};

export type TeamMessageSender = { id: number; name: string; avatar_url: string | null };

export type TeamMessage = {
    id: number;
    sender_id: number;
    recipient_id: number;
    body: string;
    meta?: { attachment?: TeamMessageAttachment | null } | null;
    reply_to_message_id: number | null;
    reply_to?: (TeamMessage & { sender?: TeamMessageSender }) | null;
    edited_at: string | null;
    deleted_at: string | null;
    created_at: string;
    sender?: TeamMessageSender;
};

/**
 * Internal 1-on-1 team chat -- deliberately its own small store rather than
 * folded into chat.ts, which is deeply tied to customer conversations
 * (channels, leads, AI drafts, Chatwoot). Team messages have none of that,
 * just two colleagues and plain polling (same convention chat.ts itself
 * uses for its own conversation list, no WebSocket infra needed here).
 * Reuses chat.ts's own field shapes for attachments/edit/delete/reply
 * (meta.attachment, edited_at, deleted_at, reply_to_message_id) so the UI
 * layer can reuse the same generic Message/Bubble/Attachment components.
 */
export const useTeamChatStore = defineStore('teamChat', () => {
    const threads = ref<TeamThread[]>([]);
    const activeUserId = ref<number | null>(null);
    const messages = ref<TeamMessage[]>([]);
    const loadingThreads = ref(false);
    const loadingMessages = ref(false);
    const sending = ref(false);
    const replyTarget = ref<TeamMessage | null>(null);

    let threadsTimer: number | undefined;
    let messagesTimer: number | undefined;

    function tenantSlug(): string | null {
        return useCrmDashboardStore().tenant?.slug ?? null;
    }

    async function loadThreads(): Promise<void> {
        loadingThreads.value = true;
        try {
            threads.value = await apiRequest<TeamThread[]>('/api/team-messages/threads', { tenant: tenantSlug() });
        } finally {
            loadingThreads.value = false;
        }
    }

    async function loadMessages(userId: number): Promise<void> {
        loadingMessages.value = true;
        try {
            messages.value = await apiRequest<TeamMessage[]>(`/api/team-messages/${userId}`, { tenant: tenantSlug() });
        } finally {
            loadingMessages.value = false;
        }
    }

    function selectThread(userId: number): void {
        activeUserId.value = userId;
        replyTarget.value = null;
        loadMessages(userId);
        window.clearInterval(messagesTimer);
        messagesTimer = window.setInterval(() => loadMessages(userId), 5000);
    }

    function unselectThread(): void {
        activeUserId.value = null;
        replyTarget.value = null;
        window.clearInterval(messagesTimer);
    }

    function setReplyTarget(message: TeamMessage): void {
        replyTarget.value = message;
    }

    function cancelReply(): void {
        replyTarget.value = null;
    }

    async function send(body: string, attachment?: TeamMessageAttachment | null): Promise<void> {
        if (! activeUserId.value) return;
        if (! body.trim() && ! attachment) return;

        sending.value = true;
        try {
            await apiRequest('/api/team-messages', {
                method: 'POST',
                body: {
                    recipient_id: activeUserId.value,
                    body: body.trim(),
                    attachment: attachment ?? null,
                    reply_to_message_id: replyTarget.value?.id ?? null,
                },
                tenant: tenantSlug(),
            });
            replyTarget.value = null;
            await loadMessages(activeUserId.value);
            await loadThreads();
        } finally {
            sending.value = false;
        }
    }

    async function uploadAttachment(file: File, type: TeamMessageAttachment['type']): Promise<TeamMessageAttachment | null> {
        const form = new FormData();
        form.append('file', file);
        form.append('type', type);

        try {
            return await apiRequest<TeamMessageAttachment>('/api/team-messages/attachments', { method: 'POST', body: form, tenant: tenantSlug() });
        } catch {
            return null;
        }
    }

    async function editMessage(messageId: number, body: string): Promise<void> {
        if (! activeUserId.value) return;
        await apiRequest(`/api/team-messages/${messageId}`, { method: 'PATCH', body: { body }, tenant: tenantSlug() });
        await loadMessages(activeUserId.value);
    }

    async function deleteMessage(messageId: number): Promise<void> {
        if (! activeUserId.value) return;
        await apiRequest(`/api/team-messages/${messageId}`, { method: 'DELETE', tenant: tenantSlug() });
        await loadMessages(activeUserId.value);
    }

    function init(): void {
        loadThreads();
        threadsTimer = window.setInterval(loadThreads, 15000);
    }

    function dispose(): void {
        window.clearInterval(threadsTimer);
        window.clearInterval(messagesTimer);
    }

    const activeThread = computed(() => threads.value.find((thread) => thread.user.id === activeUserId.value) ?? null);
    const totalUnread = computed(() => threads.value.reduce((sum, thread) => sum + thread.unread_count, 0));

    return {
        threads, activeUserId, messages, loadingThreads, loadingMessages, sending, replyTarget,
        activeThread, totalUnread,
        init, dispose, selectThread, unselectThread, send, uploadAttachment, editMessage, deleteMessage,
        setReplyTarget, cancelReply, loadThreads,
    };
});
