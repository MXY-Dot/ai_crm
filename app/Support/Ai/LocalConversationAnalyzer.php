<?php

namespace App\Support\Ai;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;

class LocalConversationAnalyzer
{
    public function analyze(Conversation $conversation, Message $message, Lead $lead, int $handoffThreshold, ?Company $company = null, bool $isFirstMessage = false): AiDecision
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
            replyText: $this->replyText($intent, $confidence, $company, $isFirstMessage),
        );
    }

    private function intent(string $body): string
    {
        if ($this->contains($body, ['refund', 'deposit', 'payment', 'invoice', 'возврат', 'депозит', 'оплат', 'предоплат', 'счет', 'счёт'])) {
            return 'payment_policy';
        }

        if ($this->contains($body, ['angry', 'bad', 'complaint', 'cancel', 'ужасно', 'плохо', 'жалоб', 'недоволен', 'разочарован', 'отмени'])) {
            return 'complaint';
        }

        if ($this->contains($body, ['price', 'cost', 'package', 'цена', 'цену', 'стоимост', 'сколько стоит', 'сколько это', 'прайс', 'тариф'])) {
            return 'pricing_request';
        }

        if ($this->contains($body, ['book', 'slot', 'appointment', 'available', 'запис', 'слот', 'свободн', 'когда можно', 'приём', 'прием', 'записаться'])) {
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

    private function replyText(string $intent, int $confidence, ?Company $company, bool $isFirstMessage): ?string
    {
        if ($confidence < 35) {
            return null;
        }

        $reply = match ($intent) {
            'booking_request' => 'Спасибо за обращение! Уточните, пожалуйста, удобные дату и время — мы подтвердим запись в ближайшее время.',
            'pricing_request' => 'Спасибо за интерес к нашим услугам! Стоимость зависит от деталей запроса — оператор свяжется с вами и уточнит цену.',
            'payment_policy' => 'Спасибо за обращение по вопросу оплаты — передали его оператору, он ответит по деталям в ближайшее время.',
            'complaint' => 'Приносим извинения за неудобства. Ваше обращение передано оператору, он свяжется с вами как можно скорее.',
            default => 'Спасибо за сообщение! Мы получили ваш запрос, оператор ответит на уточняющие вопросы в ближайшее время.',
        };

        if (! $isFirstMessage || ! $company) {
            return $reply;
        }

        $greeting = $this->greeting($company);

        return $greeting !== '' ? $greeting.' '.$reply : $reply;
    }

    private function greeting(Company $company): string
    {
        if (trim((string) $company->name) === '') {
            return '';
        }

        $parts = array_filter([
            'Здравствуйте! Это '.$company->name.'.',
            $company->phone ? 'Если удобнее, можете позвонить нам: '.$company->phone.'.' : null,
        ]);

        return implode(' ', $parts);
    }

    private function summary(Conversation $conversation, Message $message, Lead $lead, string $intent): string
    {
        return sprintf(
            'Намерение: %s. Лид «%s» из диалога «%s». Последнее сообщение клиента: %s',
            $this->intentLabel($intent),
            $lead->title,
            $conversation->subject,
            mb_strimwidth($message->body, 0, 140, '...')
        );
    }

    private function intentLabel(string $intent): string
    {
        return match ($intent) {
            'booking_request' => 'запрос на запись',
            'pricing_request' => 'вопрос о цене',
            'payment_policy' => 'вопрос по оплате',
            'complaint' => 'жалоба',
            default => 'общий вопрос',
        };
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
