import type { ChatModel, MessageFileModel, MessageModel, User } from '@advanced-chat/components';
import type { MessageAttachment, ProfileUser } from '../../stores/crmDashboard';
import type { ChatConversation, ChatMessage, Typer } from './types';

/**
 * @advanced-chat/components ids are all strings (`Id = string`) — WERO's are
 * numbers everywhere. Every id crossing this boundary gets String()'d going in
 * and Number()'d coming back out (see InboxWorkspaceAdvanced.vue's handlers).
 */

/**
 * Deliberate simplification for this first pass: operator AND AI messages both
 * map to the same synthetic sender id, so the library's binary "mine vs not
 * mine" alignment reproduces the old UI's grouping (customer on one side, "us"
 * on the other) without a real per-operator identity — `Message` has no
 * `sender_id` column, only a `sender_name` string, and there's no Chatwoot/AI
 * agent id to hang a second identity off. The sender's real name/AI label is
 * still shown per-message via `sender.name`.
 */
const US_SENDER_ID = 'us';

export function toAdvancedCurrentUser(user: ProfileUser | null): User {
    return {
        id: US_SENDER_ID,
        name: user?.name ?? 'Оператор',
        avatar: user?.avatar_url ?? undefined,
        status: { state: 'online' },
    };
}

function senderFor(message: ChatMessage): User {
    if (message.sender_type === 'customer') {
        return { id: 'customer:' + message.conversation_id, name: message.sender_name ?? 'Клиент', status: { state: 'online' } };
    }

    if (message.sender_type === 'ai') {
        return { id: US_SENDER_ID, name: message.sender_name ?? 'AI', status: { state: 'online' } };
    }

    return { id: US_SENDER_ID, name: message.sender_name ?? 'Оператор', status: { state: 'online' } };
}

function extensionOf(name: string): string {
    const dot = name.lastIndexOf('.');
    return dot === -1 ? '' : name.slice(dot + 1);
}

export function toMessageFiles(attachment?: MessageAttachment | null): MessageFileModel[] {
    if (! attachment) return [];

    const name = attachment.filename ?? attachment.url.split('/').pop() ?? 'file';

    return [{
        name,
        type: attachment.mime ?? guessMimeFromWeroType(attachment.type),
        extension: extensionOf(name),
        url: attachment.url,
    }];
}

function guessMimeFromWeroType(type: MessageAttachment['type']): string {
    switch (type) {
        case 'photo': return 'image/*';
        case 'voice': return 'audio/*';
        case 'video': return 'video/*';
        default: return 'application/octet-stream';
    }
}

/** Reverse of guessMimeFromWeroType() — classifies a browser File picked in the library's composer back into WERO's upload `type` param. */
export function guessWeroType(mime: string): MessageAttachment['type'] {
    if (mime.startsWith('image/')) return 'photo';
    if (mime.startsWith('audio/')) return 'voice';
    if (mime.startsWith('video/')) return 'video';
    return 'document';
}

export function toAdvancedMessages(messages: ChatMessage[]): MessageModel[] {
    return messages.map((message) => ({
        id: String(message.id),
        sender: senderFor(message),
        content: message.body,
        createdAt: message.sent_at ?? new Date().toISOString(),
        status: message.status,
        deleted: Boolean(message.deleted_at),
        edited: Boolean(message.edited_at),
        files: toMessageFiles(message.meta?.attachment),
        disableActions: true,
    }));
}

export function toAdvancedChats(conversations: ChatConversation[], activeConversationId: number | null, activeTypers: Typer[]): ChatModel[] {
    return conversations.map((conversation) => ({
        id: String(conversation.id),
        name: conversation.customer?.name ?? conversation.subject,
        unreadCount: conversation.unread_count,
        lastMessage: conversation.ai_summary ? { id: 'summary', sender: { id: 'system', name: '', status: { state: 'offline' } }, content: conversation.ai_summary, createdAt: conversation.last_message_at ?? new Date().toISOString() } : undefined,
        typingUsers: conversation.id === activeConversationId
            ? activeTypers.map((typer): User => ({ id: String(typer.user_id), name: typer.name, status: { state: 'online' } }))
            : undefined,
    }));
}
