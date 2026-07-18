<?php

namespace App\Support\Ai;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;

class LocalConversationAnalyzer
{
    public function analyze(Conversation $conversation, Message $message, Lead $lead, int $handoffThreshold): AiDecision
    {
        $body = mb_strtolower($message->body);
        $intent = $this->intent($body);
        $confidence = $this->confidence($body, $intent);
        $nextAction = $this->nextAction($intent, $confidence, $handoffThreshold);
        $handoffRequired = $confidence < $handoffThreshold || in_array($intent, ['payment_policy', 'complaint'], true);

        return new AiDecision(
            confidence: $confidence,
            intent: $intent,
            summary: $this->summary($conversation, $message, $lead, $intent),
            nextAction: $nextAction,
            handoffRequired: $handoffRequired,
        );
    }

    private function intent(string $body): string
    {
        if ($this->contains($body, ['refund', 'deposit', 'payment', 'invoice'])) {
            return 'payment_policy';
        }

        if ($this->contains($body, ['angry', 'bad', 'complaint', 'cancel'])) {
            return 'complaint';
        }

        if ($this->contains($body, ['price', 'cost', 'package'])) {
            return 'pricing_request';
        }

        if ($this->contains($body, ['book', 'slot', 'appointment', 'available'])) {
            return 'booking_request';
        }

        return 'general_question';
    }

    private function confidence(string $body, string $intent): int
    {
        $score = match ($intent) {
            'booking_request' => 84,
            'pricing_request' => 76,
            'payment_policy' => 58,
            'complaint' => 52,
            default => 68,
        };

        if (mb_strlen($body) < 12) {
            $score -= 12;
        }

        if (str_contains($body, '?')) {
            $score += 4;
        }

        return max(20, min(95, $score));
    }

    private function nextAction(string $intent, int $confidence, int $handoffThreshold): string
    {
        if ($confidence < $handoffThreshold) {
            return 'handoff_operator';
        }

        return match ($intent) {
            'booking_request' => 'suggest_slots',
            'pricing_request' => 'send_offer',
            'payment_policy', 'complaint' => 'handoff_operator',
            default => 'draft_reply',
        };
    }

    private function summary(Conversation $conversation, Message $message, Lead $lead, string $intent): string
    {
        return sprintf(
            'Intent: %s. Lead "%s" from conversation "%s". Latest customer message: %s',
            str_replace('_', ' ', $intent),
            $lead->title,
            $conversation->subject,
            mb_strimwidth($message->body, 0, 140, '...')
        );
    }

    private function contains(string $body, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($body, $needle)) {
                return true;
            }
        }

        return false;
    }
}