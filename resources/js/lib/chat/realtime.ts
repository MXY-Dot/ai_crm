import { getMessages, getTypers } from './api';
import { getEcho } from './echo';
import type { ChatMessage, Typer } from './types';

/**
 * Realtime transport contract for the chat feature. `WebSocketChatRealtimeTransport`
 * (Reverb, via Laravel Echo) is the transport used today for message delivery —
 * `PollingChatRealtimeTransport` remains as the transport for typing/AI-generating
 * presence (still REST-polled) and as a fallback implementation. Both conform to
 * this same interface so the store and UI never need to know which one is active.
 */
export interface ChatRealtimeTransport {
    start(): void;
    stop(): void;
    /** Begin receiving `message`/`typing` events for this conversation, starting after `lastMessageId`. */
    watchConversation(conversationId: number, lastMessageId: number): void;
    unwatchConversation(conversationId: number): void;
    /** Update the high-water mark after messages are appended some other way (e.g. an optimistic send resolved). */
    setLastSeenMessageId(conversationId: number, messageId: number): void;
    onMessage(handler: (conversationId: number, message: ChatMessage) => void): () => void;
    /** A message landed in some conversation on this tenant, whether or not it's currently watched — for sidebar reordering/unread without polling. */
    onConversationTouched(handler: (conversationId: number, message: ChatMessage) => void): () => void;
    onTyping(handler: (conversationId: number, typers: Typer[]) => void): () => void;
    /** AI is generating a reply for this conversation right now (backed by ProcessAiReplyJob). */
    onAiGenerating(handler: (conversationId: number, generating: boolean) => void): () => void;
}

const MESSAGE_POLL_MS = 4000;
const TYPING_POLL_MS = 3000;

export class PollingChatRealtimeTransport implements ChatRealtimeTransport {
    private messageTimer: number | null = null;
    private typingTimer: number | null = null;
    private watched = new Map<number, number>(); // conversationId -> lastSeenMessageId
    private messageHandlers: Array<(conversationId: number, message: ChatMessage) => void> = [];
    private typingHandlers: Array<(conversationId: number, typers: Typer[]) => void> = [];
    private aiGeneratingHandlers: Array<(conversationId: number, generating: boolean) => void> = [];
    private inFlight = false;

    constructor(private readonly tenant: () => string | null) {}

    start(): void {
        if (this.messageTimer !== null) return;
        this.messageTimer = window.setInterval(() => this.pollMessages(), MESSAGE_POLL_MS);
        this.typingTimer = window.setInterval(() => this.pollTyping(), TYPING_POLL_MS);
    }

    stop(): void {
        if (this.messageTimer !== null) window.clearInterval(this.messageTimer);
        if (this.typingTimer !== null) window.clearInterval(this.typingTimer);
        this.messageTimer = null;
        this.typingTimer = null;
        this.watched.clear();
    }

    watchConversation(conversationId: number, lastMessageId: number): void {
        this.watched.set(conversationId, lastMessageId);
    }

    unwatchConversation(conversationId: number): void {
        this.watched.delete(conversationId);
    }

    setLastSeenMessageId(conversationId: number, messageId: number): void {
        if (this.watched.has(conversationId) && messageId > (this.watched.get(conversationId) ?? 0)) {
            this.watched.set(conversationId, messageId);
        }
    }

    onMessage(handler: (conversationId: number, message: ChatMessage) => void): () => void {
        this.messageHandlers.push(handler);
        return () => { this.messageHandlers = this.messageHandlers.filter((h) => h !== handler); };
    }

    /** No-op here — polling already covers every conversation via the store's own sidebar poll timer. */
    onConversationTouched(): () => void {
        return () => {};
    }

    onTyping(handler: (conversationId: number, typers: Typer[]) => void): () => void {
        this.typingHandlers.push(handler);
        return () => { this.typingHandlers = this.typingHandlers.filter((h) => h !== handler); };
    }

    onAiGenerating(handler: (conversationId: number, generating: boolean) => void): () => void {
        this.aiGeneratingHandlers.push(handler);
        return () => { this.aiGeneratingHandlers = this.aiGeneratingHandlers.filter((h) => h !== handler); };
    }

    private async pollMessages(): Promise<void> {
        const tenant = this.tenant();
        if (! tenant || this.inFlight || document.hidden || this.watched.size === 0) return;

        this.inFlight = true;
        try {
            for (const [conversationId, lastSeenId] of this.watched.entries()) {
                const page = await getMessages(tenant, conversationId, { after: lastSeenId });
                for (const message of page.data) {
                    this.watched.set(conversationId, Math.max(this.watched.get(conversationId) ?? 0, message.id));
                    this.messageHandlers.forEach((handler) => handler(conversationId, message));
                }
            }
        } catch {
            // Silent — the next tick retries. A single failed poll shouldn't surface as a user-facing error.
        } finally {
            this.inFlight = false;
        }
    }

    private async pollTyping(): Promise<void> {
        const tenant = this.tenant();
        if (! tenant || document.hidden || this.watched.size === 0) return;

        for (const conversationId of this.watched.keys()) {
            try {
                const { typers, ai_generating: aiGenerating } = await getTypers(tenant, conversationId);
                this.typingHandlers.forEach((handler) => handler(conversationId, typers));
                this.aiGeneratingHandlers.forEach((handler) => handler(conversationId, aiGenerating));
            } catch {
                // Silent, same reasoning as pollMessages.
            }
        }
    }
}

type EchoChannel = {
    listen(event: string, callback: (payload: unknown) => void): EchoChannel;
    stopListening(event: string, callback?: (payload: unknown) => void): EchoChannel;
};

/**
 * Message delivery over the Reverb WebSocket (see App\Events\MessageCreated /
 * Message::booted()): a customer's message shows up the instant it's saved,
 * with no polling delay — AI generation runs later, separately, and its reply
 * arrives the same way once it's created. Typing/AI-generating presence still
 * rides on `PollingChatRealtimeTransport` internally; that wasn't the bottleneck.
 */
export class WebSocketChatRealtimeTransport implements ChatRealtimeTransport {
    private readonly typingTransport: PollingChatRealtimeTransport;
    private conversationChannels = new Map<number, EchoChannel>();
    private tenantChannel: EchoChannel | null = null;
    private tenantChannelListener: ((payload: unknown) => void) | null = null;
    private watchedTenantId: number | null = null;
    private watchedLastSeen = new Map<number, number>();
    private messageHandlers: Array<(conversationId: number, message: ChatMessage) => void> = [];
    private conversationTouchedHandlers: Array<(conversationId: number, message: ChatMessage) => void> = [];

    constructor(tenant: () => string | null, private readonly tenantId: () => number | null) {
        this.typingTransport = new PollingChatRealtimeTransport(tenant);
    }

    start(): void {
        this.typingTransport.start();
        this.subscribeTenantChannel();
    }

    stop(): void {
        this.typingTransport.stop();

        for (const conversationId of [...this.conversationChannels.keys()]) {
            this.unwatchConversation(conversationId);
        }

        // Deliberately NOT getEcho().leave() here: this tenant-wide channel is shared
        // with useUnreadStore, which subscribes to the exact same channel name for the
        // whole session (started once in AppLayout.vue) — Echo dedupes same-named
        // channels into one underlying subscription, so leave() here used to tear the
        // whole thing down, silently killing the unread store's own listener the first
        // time an operator opened then left /inbox, for the rest of the session (found
        // live: notifications only ever fired for whichever channel/provider was tested
        // before /inbox was ever opened once). stopListening() removes only this
        // transport's own callback and leaves the shared channel intact.
        if (this.tenantChannel && this.tenantChannelListener) {
            this.tenantChannel.stopListening('.message.created', this.tenantChannelListener);
        }
        this.tenantChannel = null;
        this.tenantChannelListener = null;
        this.watchedTenantId = null;
    }

    watchConversation(conversationId: number, lastMessageId: number): void {
        this.watchedLastSeen.set(conversationId, lastMessageId);
        this.typingTransport.watchConversation(conversationId, lastMessageId);
        this.subscribeTenantChannel();

        if (this.conversationChannels.has(conversationId)) return;

        const channel = getEcho()
            .private(`conversation.${conversationId}`)
            .listen('.message.created', (payload) => {
                const message = (payload as { message: ChatMessage }).message;
                const seen = this.watchedLastSeen.get(conversationId) ?? 0;
                if (message.id <= seen) return;
                this.watchedLastSeen.set(conversationId, message.id);
                this.messageHandlers.forEach((handler) => handler(conversationId, message));
            });

        this.conversationChannels.set(conversationId, channel as unknown as EchoChannel);
    }

    unwatchConversation(conversationId: number): void {
        this.watchedLastSeen.delete(conversationId);
        this.typingTransport.unwatchConversation(conversationId);

        if (this.conversationChannels.has(conversationId)) {
            getEcho().leave(`conversation.${conversationId}`);
            this.conversationChannels.delete(conversationId);
        }
    }

    setLastSeenMessageId(conversationId: number, messageId: number): void {
        if (messageId > (this.watchedLastSeen.get(conversationId) ?? 0)) this.watchedLastSeen.set(conversationId, messageId);
        this.typingTransport.setLastSeenMessageId(conversationId, messageId);
    }

    onMessage(handler: (conversationId: number, message: ChatMessage) => void): () => void {
        this.messageHandlers.push(handler);
        return () => { this.messageHandlers = this.messageHandlers.filter((h) => h !== handler); };
    }

    onConversationTouched(handler: (conversationId: number, message: ChatMessage) => void): () => void {
        this.conversationTouchedHandlers.push(handler);
        return () => { this.conversationTouchedHandlers = this.conversationTouchedHandlers.filter((h) => h !== handler); };
    }

    onTyping(handler: (conversationId: number, typers: Typer[]) => void): () => void {
        return this.typingTransport.onTyping(handler);
    }

    onAiGenerating(handler: (conversationId: number, generating: boolean) => void): () => void {
        return this.typingTransport.onAiGenerating(handler);
    }

    private subscribeTenantChannel(): void {
        const id = this.tenantId();
        if (! id || this.tenantChannel) return;

        const listener = (payload: unknown): void => {
            const message = (payload as { message: ChatMessage }).message;
            this.conversationTouchedHandlers.forEach((handler) => handler(message.conversation_id, message));
        };

        this.tenantChannel = getEcho()
            .private(`tenant.${id}.conversations`)
            .listen('.message.created', listener) as unknown as EchoChannel;
        this.tenantChannelListener = listener;

        this.watchedTenantId = id;
    }
}

export function createChatRealtimeTransport(tenant: () => string | null, tenantId: () => number | null): ChatRealtimeTransport {
    return new WebSocketChatRealtimeTransport(tenant, tenantId);
}
