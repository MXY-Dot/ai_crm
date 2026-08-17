<?php

namespace App\Support\Ai;

use App\Models\AiAgent;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Emergency\HealthMonitor;
use App\Support\Integrations\TenantIntegrationSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

class DifyClient
{
    public function __construct(
        private readonly TenantIntegrationSettings $secrets,
        private readonly HealthMonitor $health,
    ) {
    }

    public function decide(AiAgent $agent, Conversation $conversation, Message $message, Lead $lead, bool $isFirstMessage = false): ?AiDecision
    {
        $settings = $agent->tenant?->settings ?? [];
        $baseUrl = $agent->tenant ? $this->secrets->difyUrl($agent->tenant) : rtrim((string) config('services.dify.url', ''), '/');
        $apiKey = $agent->tenant ? $this->secrets->difyApiKey($agent->tenant) : (string) config('services.dify.api_key', '');
        $timeout = (int) Arr::get($settings, 'integrations.dify.timeout', config('services.dify.timeout', 12));

        if ($baseUrl === '' || $apiKey === '') {
            return null;
        }

        $tenantId = $agent->tenant_id;

        // Circuit breaker (ЭТАП 16.1/16.2): this tenant's Dify instance already
        // tripped FAILURE_THRESHOLD consecutive failures — skip the call and go
        // straight to the direct-LLM/local-mvp fallback chain in AiWorkflow.
        if ($this->health->isOpen('dify:'.$tenantId, $tenantId)) {
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
                        'goal' => $agent->goal ?? '',
                        'preferred_model' => $agent->model ?? '',
                        'is_first_message' => $isFirstMessage ? 'yes' : 'no',
                        'recent_messages' => $this->recentMessages($conversation),
                        'knowledge_context' => $this->knowledgeContext($agent),
                        'business_profile' => $this->businessProfile($agent),
                    ],
                    'query' => $this->query($agent, $conversation, $message, $lead, $isFirstMessage),
                    'response_mode' => 'blocking',
                    'user' => 'tenant-'.$agent->tenant_id.'-conversation-'.$conversation->id,
                ]);
        } catch (Throwable $error) {
            $this->health->recordFailure('dify:'.$tenantId, $tenantId, 'connection_failed', $error->getMessage());

            return null;
        }

        if (! $response->successful()) {
            $this->health->recordFailure('dify:'.$tenantId, $tenantId, 'http_'.$response->status(), mb_strimwidth($response->body(), 0, 300, '...'));

            return null;
        }

        $this->health->recordSuccess('dify:'.$tenantId, $tenantId);

        return $this->decisionFrom($response->json(), $agent, $conversation, $message, $lead);
    }

    /**
     * Trivial connection test, same request shape as
     * IntegrationSettingsController::testDify() — used only by
     * ActiveHealthProbe::probeDifyRecovery() to attempt closing a tripped circuit;
     * never called from the live message path. Deliberately does not touch
     * HealthMonitor itself — the caller records the outcome, since a probe success
     * needs to count toward SUCCESS_THRESHOLD the same way a real decide() success
     * would, and a probe failure shouldn't re-open an already-open incident.
     */
    public function ping(Tenant $tenant): bool
    {
        $baseUrl = $this->secrets->difyUrl($tenant);
        $apiKey = $this->secrets->difyApiKey($tenant);

        if ($baseUrl === '' || $apiKey === '') {
            return false;
        }

        try {
            $response = Http::timeout(8)
                ->connectTimeout(4)
                ->acceptJson()
                ->withToken($apiKey)
                ->post($baseUrl.'/chat-messages', [
                    'inputs' => ['connection_test' => true],
                    'query' => 'WERO health check. Reply with ok.',
                    'response_mode' => 'blocking',
                    'user' => 'tenant-'.$tenant->id.'-health-probe',
                ]);
        } catch (Throwable) {
            return false;
        }

        return $response->successful();
    }

    private function query(AiAgent $agent, Conversation $conversation, Message $message, Lead $lead, bool $isFirstMessage): string
    {
        return implode("\n\n", array_filter([
            'You are the CRM AI assistant for this company. Never identify as DeepSeek, ChatGPT, Dify, or a generic language model. Answer as the company assistant.',
            'Return a helpful customer-facing answer. If the customer asks about something outside the company rules, ask one short clarifying question or hand off to an operator.',
            $agent->model ? 'Preferred AI model for this agent: '.$agent->model.'. If your Dify app routes by model, use it; otherwise ignore this line.' : '',
            $agent->goal ? 'Your goal for this conversation is to guide the customer toward: '.$agent->goal.'. Keep this in mind without being pushy.' : '',
            'Customers in this region commonly write in colloquial Tajik (Cyrillic script), a mix of Tajik and Russian within one message, or Tajik transliterated into Latin letters. Treat all of these as completely normal — never ask what language the customer is writing in, never comment on mixed or transliterated spelling, and reply naturally in the same language/mix the customer used.',
            $isFirstMessage ? "This is the customer's first message in this conversation. Begin your reply with a brief, natural greeting that states the company name (and phone number if it helps the customer), then answer their question." : '',
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
        $summary = $this->cleanGeneratedText((string) Arr::get($data, 'summary', $rawAnswer !== '' ? $rawAnswer : 'AI response for '.$lead->title));
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

    public function recentMessages(Conversation $conversation): string
    {
        return $conversation->messages()
            ->latest('sent_at')
            ->limit(8)
            ->get()
            ->reverse()
            ->map(fn (Message $message): string => $message->sender_type.': '.mb_strimwidth($message->body, 0, 300, '...'))
            ->implode("\n");
    }

    public function knowledgeContext(AiAgent $agent): string
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

    public function businessProfile(AiAgent $agent): string
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
        return 'AI processed conversation "'.$conversation->subject.'". Latest message: '.mb_strimwidth($message->body, 0, 140, '...');
    }
}
