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
    ) {
    }
}