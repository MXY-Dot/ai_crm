<?php

namespace App\Support\Ai;

use App\Models\AiAgent;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\Lead;
use App\Models\Message;
use App\Support\Integrations\TenantIntegrationSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

class DifyClient
{
    public function __construct(private readonly TenantIntegrationSettings $secrets)
    {
    }

    public function decide(AiAgent $agent, Conversation $conversation, Message $message, Lead $lead): ?AiDecision
    {
        $settings = $agent->tenant?->settings ?? [];
        $baseUrl = $agent->tenant ? $this->secrets->difyUrl($agent->tenant) : rtrim((string) config('services.dify.url', ''), '/');
        $apiKey = $agent->tenant ? $this->secrets->difyApiKey($agent->tenant) : (string) config('services.dify.api_key', '');
        $timeout = (int) Arr::get($settings, 'integrations.dify.timeout', config('services.dify.timeout', 12));

        if ($baseUrl === '' || $apiKey === '') {
            return null;
        }

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(4)
                ->acceptJson()
                ->withToken($apiKey)
                ->post($baseUrl.'/chat-messages', [
                    'inputs' => [
                        'conversation_subject' => $conversation->subject,
                        'lead_title' => $lead->title,
                        'handoff_threshold' => $agent->handoff_threshold,
                        'instructions' => $agent->instructions,
                        'recent_messages' => $this->recentMessages($conversation),
                        'knowledge_context' => $this->knowledgeContext($agent),
                        'business_profile' => $this->businessProfile($agent),
                    ],
                    'query' => $this->query($agent, $conversation, $message, $lead),
                    'response_mode' => 'blocking',
                    'user' => 'tenant-'.$agent->tenant_id.'-conversation-'.$conversation->id,
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return $this->decisionFrom($response->json(), $agent, $conversation, $message, $lead);
    }

    private function query(AiAgent $agent, Conversation $conversation, Message $message, Lead $lead): string
    {
        return implode("\n\n", array_filter([
            'You are the CRM AI assistant for this company. Never identify as DeepSeek, ChatGPT, Dify, or a generic language model. Answer as the company assistant.',
            'Return a helpful customer-facing answer. If the customer asks about something outside the company rules, ask one short clarifying question or hand off to an operator.',
            'Agent instructions: '.$agent->instructions,
            'Lead: '.$lead->title,
            'Conversation: '.$conversation->subject,
            'Business profile:' . "\n" . $this->businessProfile($agent),
            'Knowledge base:' . "\n" . $this->knowledgeContext($agent),
            'Recent messages:' . "\n" . $this->recentMessages($conversation),
            'Customer message:' . "\n" . $message->body,
            'Respond as JSON with keys: confidence, intent, summary, reply_text, next_action, handoff_required.',
        ], fn (string $part): bool => trim($part) !== ''));
    }
    private function decisionFrom(array $payload, AiAgent $agent, Conversation $conversation, Message $message, Lead $lead): AiDecision
    {
        $data = $this->extractData($payload);
        $rawAnswer = is_string(Arr::get($payload, 'answer')) ? $this->cleanGeneratedText((string) Arr::get($payload, 'answer')) : '';
        $confidence = $this->confidence(Arr::get($data, 'confidence', 65));
        $intent = (string) Arr::get($data, 'intent', 'general_question');
        $nextAction = (string) Arr::get($data, 'next_action', ($confidence < $agent->handoff_threshold ? 'handoff_operator' : 'draft_reply'));
        $summary = $this->cleanGeneratedText((string) Arr::get($data, 'summary', $rawAnswer !== '' ? $rawAnswer : 'Dify response for '.$lead->title));
        $replyText = $this->replyText($data, $rawAnswer);
        $handoff = filter_var(Arr::get($data, 'handoff_required', $confidence < $agent->handoff_threshold), FILTER_VALIDATE_BOOL);

        return new AiDecision(
            confidence: $confidence,
            intent: $intent,
            summary: $summary !== '' ? $summary : $this->fallbackSummary($conversation, $message),
            nextAction: $nextAction !== '' ? $nextAction : 'draft_reply',
            handoffRequired: $handoff,
            replyText: $replyText,
        );
    }

    private function extractData(array $payload): array
    {
        $answer = Arr::get($payload, 'answer');

        if (is_array($answer)) {
            return $answer;
        }

        if (is_string($answer)) {
            $decoded = $this->decodeJsonAnswer($answer);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $data = Arr::get($payload, 'metadata.ai_decision');

        return is_array($data) ? $data : [];
    }

    private function replyText(array $data, string $rawAnswer): ?string
    {
        $reply = (string) (Arr::get($data, 'reply') ?? Arr::get($data, 'reply_text') ?? Arr::get($data, 'draft_reply') ?? '');

        if ($reply !== '') {
            return $this->cleanGeneratedText($reply);
        }

        return $data === [] && $rawAnswer !== '' ? $this->cleanGeneratedText($rawAnswer) : null;
    }

    private function decodeJsonAnswer(string $answer): ?array
    {
        $clean = $this->cleanGeneratedText($answer);
        $clean = preg_replace('/^\s*```(?:json)?\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/\s*```\s*$/', '', $clean) ?? $clean;
        $clean = trim($clean);

        $decoded = json_decode($clean, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $clean, $match)) {
            $decoded = json_decode($match[0], true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function confidence(mixed $value): int
    {
        if (is_numeric($value)) {
            $number = (float) $value;

            if ($number > 0 && $number <= 1) {
                $number *= 100;
            }

            return max(0, min(100, (int) round($number)));
        }

        return 65;
    }
    private function cleanGeneratedText(string $text): string
    {
        $text = preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $text) ?? $text;
        $text = preg_replace('/^\s*<\/?think\b[^>]*>\s*/im', '', $text) ?? $text;

        return trim($text);
    }

    private function recentMessages(Conversation $conversation): string
    {
        return $conversation->messages()
            ->latest('sent_at')
            ->limit(8)
            ->get()
            ->reverse()
            ->map(fn (Message $message): string => $message->sender_type.': '.mb_strimwidth($message->body, 0, 300, '...'))
            ->implode("\n");
    }

    private function knowledgeContext(AiAgent $agent): string
    {
        return KnowledgeChunk::withoutGlobalScopes()
            ->where('tenant_id', $agent->tenant_id)
            ->whereHas('document', fn ($query) => $query
                ->where('status', 'indexed')
                ->where(fn ($scope) => $scope->whereNull('ai_agent_id')->orWhere('ai_agent_id', $agent->id)))
            ->orderBy('knowledge_document_id')
            ->orderBy('position')
            ->limit(6)
            ->pluck('content')
            ->map(fn (string $content): string => mb_strimwidth($content, 0, 500, '...'))
            ->implode("\n---\n");
    }

    private function businessProfile(AiAgent $agent): string
    {
        $company = $agent->company;

        if (! $company) {
            return '';
        }

        $brand = $company->brand_settings ?? [];
        $hours = $company->working_hours ?? [];
        $hoursSummary = is_array($hours) ? ($hours['summary'] ?? json_encode($hours)) : '';

        $lines = array_filter([
            'Company: '.$company->name,
            'Industry: '.($company->industry ?? ''),
            'Phone: '.($company->phone ?? ''),
            'Address: '.($company->address ?? ''),
            'Working hours: '.$hoursSummary,
            'Services and prices: '.(is_array($brand) ? ($brand['services'] ?? '') : ''),
            'Booking rules: '.(is_array($brand) ? ($brand['booking_rules'] ?? '') : ''),
            'Cancellation policy: '.(is_array($brand) ? ($brand['cancellation_policy'] ?? '') : ''),
        ], fn (string $line): bool => trim(substr($line, strpos($line, ':') + 1)) !== '');

        return implode("\n", $lines);
    }
    private function fallbackSummary(Conversation $conversation, Message $message): string
    {
        return 'Dify processed conversation "'.$conversation->subject.'". Latest message: '.mb_strimwidth($message->body, 0, 140, '...');
    }
}