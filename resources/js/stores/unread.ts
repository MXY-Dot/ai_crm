import { ref } from 'vue';
import { defineStore } from 'pinia';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { apiRequest } from '../lib/apiClient';
import { getEcho } from '../lib/chat/echo';
import { useCrmDashboardStore } from './crmDashboard';

type IncomingMessage = {
    id: number;
    conversation_id: number;
    sender_type: string;
    sender_name: string | null;
    body: string;
};

/**
 * App-wide "unread customer messages" count, independent of the chat store
 * (which only exists while /inbox is actually mounted — see InboxWorkspace.vue).
 * This one lives for the whole session (started once from AppLayout.vue) so the
 * sidebar/notification-bell badges stay correct on every page, not just chat.
 *
 * Instant bumps arrive over the same Reverb tenant channel the chat feature
 * already uses (see App\Events\MessageCreated); a periodic poll is the source of
 * truth and corrects any drift (e.g. a message read from within an open chat).
 *
 * Also owns the WhatsApp/Telegram-style "new message" alert: a clickable toast
 * (jumps straight to the conversation via /inbox?conversation=ID — see
 * InboxWorkspace.vue's deep-link handling) and a blinking tab title while the
 * tab isn't focused. `chat.ts` reports which conversation is currently open
 * (setActiveConversation) so a message in the thread you're already looking at
 * doesn't also pop a redundant toast on top of the message bubble itself.
 */
export const useUnreadStore = defineStore('unread', () => {
    const dashboard = useCrmDashboardStore();
    const total = ref(0);
    const activeConversationId = ref<number | null>(null);

    let pollTimer: number | null = null;
    let unsubscribe: (() => void) | null = null;
    let watchedTenantId: number | null = null;

    let blinkTimer: number | null = null;
    let blinkOriginalTitle = document.title;
    let blinkOn = false;

    function startBlink(): void {
        if (blinkTimer !== null || ! document.hidden) return;

        blinkOriginalTitle = document.title;
        blinkTimer = window.setInterval(() => {
            blinkOn = ! blinkOn;
            document.title = blinkOn ? '🔴 Новое сообщение' : blinkOriginalTitle;
        }, 1000);
    }

    function stopBlink(): void {
        if (blinkTimer !== null) window.clearInterval(blinkTimer);
        blinkTimer = null;
        blinkOn = false;
        document.title = blinkOriginalTitle;
    }

    document.addEventListener('visibilitychange', () => {
        if (! document.hidden) stopBlink();
    });

    function setActiveConversation(conversationId: number | null): void {
        activeConversationId.value = conversationId;
    }

    function notifyNewMessage(message: IncomingMessage): void {
        if (message.conversation_id === activeConversationId.value) return;

        const preview = message.body.length > 80 ? message.body.slice(0, 80) + '…' : message.body;
        toast.message(message.sender_name || 'Новое сообщение', {
            description: preview || '📎 Вложение',
            action: {
                label: 'Открыть',
                onClick: () => router.visit(`/inbox?conversation=${message.conversation_id}`),
            },
        });

        startBlink();
    }

    async function refresh(): Promise<void> {
        const slug = dashboard.tenant?.slug;
        if (! slug) return;

        try {
            const response = await apiRequest<{ total: number }>('/api/conversations/unread-total', { tenant: slug });
            total.value = response.total;
        } catch {
            // Silent — the next poll retries; a stale badge for a few seconds isn't worth a toast.
        }
    }

    function subscribeRealtime(): void {
        const tenantId = dashboard.tenant?.id ?? null;
        if (! tenantId || watchedTenantId === tenantId) return;

        unsubscribe?.();
        watchedTenantId = tenantId;

        const channel = getEcho()
            .private(`tenant.${tenantId}.conversations`)
            .listen('.message.created', (payload: unknown) => {
                const message = (payload as { message?: IncomingMessage }).message;
                if (message?.sender_type !== 'customer') return;

                total.value += 1;
                notifyNewMessage(message);
            });

        unsubscribe = () => getEcho().leave(`tenant.${tenantId}.conversations`);
        void channel;
    }

    function start(): void {
        subscribeRealtime();
        if (pollTimer !== null) return;

        refresh();
        pollTimer = window.setInterval(() => {
            subscribeRealtime();
            if (! document.hidden) refresh();
        }, 20000);
    }

    function stop(): void {
        if (pollTimer !== null) window.clearInterval(pollTimer);
        pollTimer = null;
        unsubscribe?.();
        unsubscribe = null;
        watchedTenantId = null;
        stopBlink();
    }

    return { total, refresh, start, stop, setActiveConversation };
});
