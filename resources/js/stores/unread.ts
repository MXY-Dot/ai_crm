import { ref } from 'vue';
import { defineStore } from 'pinia';
import { apiRequest } from '../lib/apiClient';
import { getEcho } from '../lib/chat/echo';
import { useCrmDashboardStore } from './crmDashboard';

/**
 * App-wide "unread customer messages" count, independent of the chat store
 * (which only exists while /inbox is actually mounted — see InboxWorkspace.vue).
 * This one lives for the whole session (started once from AppLayout.vue) so the
 * sidebar/notification-bell badges stay correct on every page, not just chat.
 *
 * Instant bumps arrive over the same Reverb tenant channel the chat feature
 * already uses (see App\Events\MessageCreated); a periodic poll is the source of
 * truth and corrects any drift (e.g. a message read from within an open chat).
 */
export const useUnreadStore = defineStore('unread', () => {
    const dashboard = useCrmDashboardStore();
    const total = ref(0);

    let pollTimer: number | null = null;
    let unsubscribe: (() => void) | null = null;
    let watchedTenantId: number | null = null;

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
                const message = (payload as { message?: { sender_type?: string } }).message;
                if (message?.sender_type === 'customer') total.value += 1;
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
    }

    return { total, refresh, start, stop };
});
