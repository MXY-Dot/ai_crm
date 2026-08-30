<?php

namespace App\Support\Ai;

class AiDecision
{
    public function __construct(
        public readonly int $confidence,
        public readonly string $intent,
        public readonly string $summary,
        public readonly string $nextAction,
        public readonly bool $handoffRequired,
        public readonly ?string $replyText = null,
        /** Extra keys merged into the persisted Message.meta -- e.g. AiChatBookingAssistant's `offered_slots`, read back on the next turn to resolve which slot the customer picked. */
        public readonly ?array $meta = null,
    ) {
    }
}