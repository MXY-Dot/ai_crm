<?php

namespace App\Support\Inbox;

final class ConversationStatus
{
    public const OPEN = 'open';
    public const PENDING_OPERATOR = 'pending_operator';
    public const CLOSED = 'closed';

    public static function values(): array
    {
        return [self::OPEN, self::PENDING_OPERATOR, self::CLOSED];
    }

    /**
     * Chatwoot's own conversation.status vocabulary (open/pending/snoozed/resolved)
     * doesn't match WERO's 3-state model — map it instead of trusting the raw
     * string, so a Chatwoot-origin value never lands in Conversation.status outside
     * the 3 values the rest of the app actually understands.
     */
    public static function fromChatwoot(string $raw): string
    {
        return match (strtolower($raw)) {
            'resolved' => self::CLOSED,
            'pending', 'snoozed' => self::PENDING_OPERATOR,
            default => self::OPEN,
        };
    }
}
