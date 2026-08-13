import type { Message } from '@/stores/crmDashboard';

export type TicketStatus = 'open' | 'answered' | 'closed';

export type TicketMessage = { id: number; body: string; is_admin: boolean; author: string | null; created_at: string };

export const ticketStatusLabels: Record<TicketStatus, string> = { open: 'Открыт', answered: 'Отвечен', closed: 'Закрыт' };
export const ticketStatusTone: Record<TicketStatus, 'blue' | 'green' | 'neutral'> = { open: 'blue', answered: 'green', closed: 'neutral' };

/**
 * Reuses the Inbox chat bubble semantics: the ticket filer is always the
 * "customer" (left, unlabeled) and WERO staff replies are the "operator" (right, labeled).
 */
export function ticketMessagesToChat(messages: TicketMessage[]): Message[] {
    return messages.map((m) => ({
        id: m.id,
        conversation_id: 0,
        sender_type: m.is_admin ? 'operator' : 'customer',
        sender_name: m.is_admin ? (m.author ?? 'Техподдержка WERO') : m.author,
        body: m.body,
        sent_at: m.created_at,
    }));
}
