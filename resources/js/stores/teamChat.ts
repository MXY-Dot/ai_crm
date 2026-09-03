import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { apiRequest } from '../lib/apiClient';
import { useCrmDashboardStore } from './crmDashboard';

export type TeamThreadUser = { id: number; name: string; email: string; role: string; avatar_url: string | null; last_seen_at: string | null };

export type TeamThread = {
    user: TeamThreadUser;
    last_message: string | null;
    last_message_at: string | null;
    unread_count: number;
};

export type TeamMessage = {
    id: number;
    sender_id: number;
    recipient_id: number;
    body: string;
    created_at: string;
    sender?: { id: number; name: string; avatar_url: string | null };
};

/**
 * Internal 1-on-1 team chat -- deliberately its own small store rather than
 * folded into chat.ts, which is deeply tied to customer conversations
 * (channels, leads, AI drafts, Chatwoot). Team messages have none of that,
 * just two colleagues and plain polling (same convention chat.ts itself
 * uses for its own conversation list, no WebSocket infra needed here).
 */
export const useTeamChatStore = defineStore('teamChat', () => {
    const threads = ref<TeamThread[]>([]);
    const activeUserId = ref<number | null>(null);
    const messages = ref<TeamMessage[]>([]);
    const loadingThreads = ref(false);
    const loadingMessages = ref(false);
    const sending = ref(false);

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
        loadMessages(userId);
        window.clearInterval(messagesTimer);
        messagesTimer = window.setInterval(() => loadMessages(userId), 5000);
    }

    function unselectThread(): void {
        activeUserId.value = null;
        window.clearInterval(messagesTimer);
    }

    async function send(body: string): Promise<void> {
        if (! activeUserId.value || ! body.trim()) return;
        sending.value = true;
        try {
            await apiRequest('/api/team-messages', {
                method: 'POST',
                body: { recipient_id: activeUserId.value, body: body.trim() },
                tenant: tenantSlug(),
            });
            await loadMessages(activeUserId.value);
            await loadThreads();
        } finally {
            sending.value = false;
        }
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
        threads, activeUserId, messages, loadingThreads, loadingMessages, sending,
        activeThread, totalUnread,
        init, dispose, selectThread, unselectThread, send, loadThreads,
    };
});
