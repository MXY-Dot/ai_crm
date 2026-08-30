import { h, ref } from 'vue';
import type { Component } from 'vue';
import { defineStore } from 'pinia';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { MessageCircle } from '@lucide/vue';
import { apiRequest } from '../lib/apiClient';
import { getEcho } from '../lib/chat/echo';
import FacebookIcon from '../components/icons/FacebookIcon.vue';
import InstagramIcon from '../components/icons/InstagramIcon.vue';
import TelegramIcon from '../components/icons/TelegramIcon.vue';
import WhatsappIcon from '../components/icons/WhatsappIcon.vue';
import { useCrmDashboardStore } from './crmDashboard';

type IncomingMessage = {
    id: number;
    conversation_id: number;
    sender_type: string;
    sender_name: string | null;
    body: string;
};

const CHANNEL_ICON: Record<string, Component> = {
    telegram: TelegramIcon,
    whatsapp: WhatsappIcon,
    instagram: InstagramIcon,
    facebook: FacebookIcon,
};

const CHANNEL_BRAND_VAR: Record<string, string> = {
    telegram: '--brand-telegram',
    whatsapp: '--brand-whatsapp',
    instagram: '--brand-instagram-to',
    facebook: '--brand-facebook',
    website: '--brand-website',
    web: '--brand-website',
};

/** Small soft-tinted chip (icon in the channel's brand color, low-opacity background of the same color) for the toast — same recipe as ChatSidebar's letter-fallback avatars, just reused here since vue-sonner's `icon` slot takes a raw component, not a template. */
function channelToastIcon(provider: string | null | undefined): Component {
    const IconComponent = (provider && CHANNEL_ICON[provider]) || MessageCircle;
    const brandVar = provider ? CHANNEL_BRAND_VAR[provider] : undefined;

    return {
        render: () => h(
            'span',
            {
                class: 'grid size-6 shrink-0 place-items-center rounded-full',
                style: brandVar
                    ? { backgroundColor: `color-mix(in srgb, var(${brandVar}) 18%, transparent)`, color: `var(${brandVar})` }
                    : undefined,
            },
            [h(IconComponent, { class: 'size-3.5' })],
        ),
    };
}

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

    function notifyNewMessage(message: IncomingMessage, provider: string | null | undefined): void {
        if (message.conversation_id === activeConversationId.value) return;

        const preview = message.body.length > 80 ? message.body.slice(0, 80) + '…' : message.body;
        // Bottom-right + 10s (vs. the app's default top-right toasts) so this one is
        // impossible to miss and doesn't linger — per explicit user feedback that the
        // default placement was easy to miss. Scoped to this toast only via per-call
        // position/duration; the shared <Toaster> in AppLayout.vue stays top-right for
        // every other toast in the app.
        toast.message(message.sender_name || 'Новое сообщение', {
            description: preview || '📎 Вложение',
            icon: channelToastIcon(provider),
            position: 'bottom-right',
            duration: 10000,
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
                const data = payload as { message?: IncomingMessage; provider?: string | null };
                if (data.message?.sender_type !== 'customer') return;

                total.value += 1;
                notifyNewMessage(data.message, data.provider);
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
