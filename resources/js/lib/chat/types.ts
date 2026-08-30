import type { Conversation, Message, MessageAttachment } from '../../stores/crmDashboard';

export type { MessageAttachment };

export type ChatSenderType = 'customer' | 'operator' | 'ai';

export type ChatMessageReplySummary = {
    id: number;
    sender_type: ChatSenderType;
    sender_name: string | null;
    body: string;
    deleted_at: string | null;
};

/** Extends the base `Message` type (crmDashboard.ts) with edit/delete/reply + client-only optimistic-send state. */
export type ChatMessage = Message & {
    reply_to_message_id: number | null;
    reply_to: ChatMessageReplySummary | null;
    edited_at: string | null;
    deleted_at: string | null;
    /** Client-generated id for an optimistic message, set before the server assigns a real `id`. */
    clientId?: string;
    /** Absent/undefined for a message that came from the server as already-sent. */
    status?: 'sending' | 'sent' | 'failed';
};

/** Extends the base `Conversation` type with the fields the dedicated chat list endpoint returns. */
export type ChatConversation = Conversation & {
    tenant_id: number;
    company_id: number;
    channel_id: number;
    customer_id: number | null;
    lead_id: number | null;
    assigned_user_id: number | null;
    assigned_user: { id: number; name: string } | null;
    /** Personal to the requesting operator — see ConversationController::pin()/unpin(). Independent of `assigned_user`. */
    is_pinned: boolean;
    customer: { id: number; name: string; phone: string | null; avatar_url?: string | null } | null;
    unread_count: number;
};

export type Typer = { user_id: number; name: string; at: number };

export type PendingAttachment = MessageAttachment & { previewUrl?: string };

export type SendMessagePayload = {
    body: string;
    reply_to_message_id?: number | null;
    attachment?: MessageAttachment | null;
};
