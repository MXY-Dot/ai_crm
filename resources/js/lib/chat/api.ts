import { apiRequest } from '../apiClient';
import type { MessageAttachment } from '../../stores/crmDashboard';
import type { ChatConversation, ChatMessage, SendMessagePayload, Typer } from './types';

/**
 * REST layer for the chat feature — the only place that knows the actual HTTP
 * routes. UI components and the chat store never call `apiRequest` directly;
 * everything about "how a message gets to the server" lives here, so a future
 * swap (e.g. a different backend) only touches this file.
 */

type Paginated<T> = { data: T[]; meta: { current_page: number; last_page: number; total: number } };
type MessagePage = { data: ChatMessage[]; meta: { has_more: boolean; oldest_id: number | null } };

export function listConversations(tenant: string, params: { search?: string; page?: number } = {}): Promise<Paginated<ChatConversation>> {
    const query = new URLSearchParams();
    if (params.search) query.set('search', params.search);
    if (params.page) query.set('page', String(params.page));

    return apiRequest<Paginated<ChatConversation>>(`/api/conversations?${query.toString()}`, { tenant });
}

export function getMessages(tenant: string, conversationId: number, params: { before?: number; after?: number } = {}): Promise<MessagePage> {
    const query = new URLSearchParams();
    if (params.before) query.set('before', String(params.before));
    if (params.after) query.set('after', String(params.after));

    return apiRequest<MessagePage>(`/api/conversations/${conversationId}/messages?${query.toString()}`, { tenant });
}

export function sendMessage(tenant: string, conversationId: number, payload: SendMessagePayload): Promise<{ ok: boolean; message: ChatMessage; conversation: ChatConversation }> {
    return apiRequest(`/api/conversations/${conversationId}/reply`, { method: 'POST', tenant, body: payload });
}

export function editMessage(tenant: string, messageId: number, body: string): Promise<{ ok: boolean; message: ChatMessage; telegram_synced: boolean | null }> {
    return apiRequest(`/api/messages/${messageId}`, { method: 'PATCH', tenant, body: { body } });
}

export function deleteMessage(tenant: string, messageId: number): Promise<{ ok: boolean; message: ChatMessage; telegram_synced: boolean | null }> {
    return apiRequest(`/api/messages/${messageId}`, { method: 'DELETE', tenant });
}

export function uploadAttachment(tenant: string, conversationId: number, file: File, type: MessageAttachment['type']): Promise<MessageAttachment> {
    const form = new FormData();
    form.append('file', file);
    form.append('type', type);

    return apiRequest(`/api/conversations/${conversationId}/attachments`, { method: 'POST', tenant, body: form });
}

export function markConversationRead(tenant: string, conversationId: number): Promise<{ ok: boolean }> {
    return apiRequest(`/api/conversations/${conversationId}/read`, { method: 'POST', tenant });
}

export function assignConversation(tenant: string, conversationId: number, userId: number | null): Promise<ChatConversation> {
    return apiRequest(`/api/conversations/${conversationId}/assignment`, { method: 'PATCH', tenant, body: { assigned_user_id: userId } });
}

/** ЭТАП 13.6 — marks status='closed' + resolved_at, for SLA reporting. */
export function resolveConversation(tenant: string, conversationId: number): Promise<ChatConversation> {
    return apiRequest(`/api/conversations/${conversationId}/resolve`, { method: 'POST', tenant });
}

/** ЭТАП 3.7 — replaces the full label list (AI auto-labels + manual ones together). */
export function setConversationLabels(tenant: string, conversationId: number, labels: string[]): Promise<ChatConversation> {
    return apiRequest(`/api/conversations/${conversationId}/labels`, { method: 'PATCH', tenant, body: { labels } });
}

/** Personal "pin to top of my list" — independent of assignConversation(), see the backend docblock. */
export function pinConversation(tenant: string, conversationId: number): Promise<{ ok: boolean; is_pinned: boolean }> {
    return apiRequest(`/api/conversations/${conversationId}/pin`, { method: 'POST', tenant });
}

export function unpinConversation(tenant: string, conversationId: number): Promise<{ ok: boolean; is_pinned: boolean }> {
    return apiRequest(`/api/conversations/${conversationId}/pin`, { method: 'DELETE', tenant });
}

export function sendTypingHeartbeat(tenant: string, conversationId: number): Promise<{ ok: boolean }> {
    return apiRequest(`/api/conversations/${conversationId}/typing`, { method: 'POST', tenant });
}

/** "I have this conversation open" presence — see ConversationTypingController::viewHeartbeat(); distinct from the composer-keystroke typing heartbeat above. */
export function sendViewingHeartbeat(tenant: string, conversationId: number): Promise<{ ok: boolean }> {
    return apiRequest(`/api/conversations/${conversationId}/viewing`, { method: 'POST', tenant });
}

export function getTypers(tenant: string, conversationId: number): Promise<{ typers: Typer[]; ai_generating: boolean }> {
    return apiRequest(`/api/conversations/${conversationId}/typing`, { tenant });
}
