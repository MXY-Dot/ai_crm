<?php

namespace App\Support\Ai;

use App\Models\AiAgent;
use App\Models\AiRun;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\CrmTask;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Chatwoot\ChatwootClient;
use App\Support\TelegramClient;
use Illuminate\Support\Arr;
use RuntimeException;

class AiWorkflow
{
    public function __construct(
        private readonly LocalConversationAnalyzer $localAnalyzer,
        private readonly DifyClient $dify,
        private readonly LlmClient $llm,
        private readonly ChatwootClient $chatwoot,
        private readonly TelegramClient $telegram,
    ) {
    }

    public function process(Tenant $tenant, Company $company, Conversation $conversation, Lead $lead, Message $message): array
    {
        $conversation->loadMissing('channel');
        $isFirstMessage = $conversation->wasRecentlyCreated;
        $agent = $this->agent($tenant, $company, $conversation->channel?->provider);
        if ($isFirstMessage) {
            $this->requestContact($tenant, $conversation);
        }
        $this->showTyping($tenant, $conversation, true);
        [$decision, $engine, $usage] = $this->decision($agent, $company, $conversation, $message, $lead, $isFirstMessage);
        $run = $this->run($tenant, $agent, $conversation, $lead, $decision, $engine, $usage);
        $draft = $this->draftMessage($tenant, $conversation, $run, $decision, $engine);
        $this->autoReply($tenant, $conversation, $draft);
        $this->showTyping($tenant, $conversation, false);

        $lead->forceFill([
            'score' => $decision->confidence,
            'ai_summary' => $decision->summary,
            'status' => $decision->confidence >= $agent->handoff_threshold ? 'qualified' : $lead->status,
        ])->save();

        $conversation->forceFill([
            'ai_summary' => $decision->summary,
            'status' => $decision->handoffRequired ? 'pending_operator' : $conversation->status,
            'priority' => $decision->handoffRequired ? 'high' : $conversation->priority,
        ])->save();

        return [
            'agent' => $agent->fresh(),
            'run' => $run->fresh(['agent', 'conversation', 'lead']),
            'task' => $decision->handoffRequired ? $this->handoffTask($tenant, $company, $lead, $conversation, $decision) : null,
            'draft_message' => $draft?->fresh(),
        ];
    }

    private function agent(Tenant $tenant, Company $company, ?string $provider): AiAgent
    {
        $agents = AiAgent::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        if ($provider) {
            $bound = $agents->first(fn (AiAgent $candidate): bool => in_array($provider, $candidate->channels ?? [], true));

            if ($bound) {
                return $bound;
            }
        }

        $catchAll = $agents->first(fn (AiAgent $candidate): bool => empty($candidate->channels));

        if ($catchAll) {
            return $catchAll;
        }

        return AiAgent::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'name' => 'Основной ассистент'],
            [
                'provider' => 'dify',
                'status' => 'active',
                'handoff_threshold' => 70,
                'instructions' => 'Classify intent, summarize the latest customer message, draft a helpful reply, and decide whether an operator handoff is needed.',
                'channels' => [],
                'settings' => ['mode' => config('services.dify.api_key') ? 'dify' : 'local-mvp'],
            ]
        );
    }

    private function decision(AiAgent $agent, Company $company, Conversation $conversation, Message $message, Lead $lead, bool $isFirstMessage): array
    {
        $decision = $this->dify->decide($agent, $conversation, $message, $lead, $isFirstMessage);

        if ($decision) {
            return [$decision, 'dify', null];
        }

        $local = $this->localAnalyzer->analyze($conversation, $message, $lead, $agent->handoff_threshold, $company, $isFirstMessage);
        $llmCompletion = $this->directLlmReply($agent, $conversation, $message, $isFirstMessage);

        if ($llmCompletion !== null) {
            return [$this->withReplyText($local, $llmCompletion['text']), 'direct-llm', $llmCompletion];
        }

        return [$local, 'local-mvp', null];
    }

    /**
     * Mirrors resources/js/lib/plans.ts' Plan.aiProviders — which model providers
     * each subscription tier unlocks. Starter gets Gemini+DeepSeek+Groq, Pro adds
     * Claude, Business adds GPT. Groq is available on every tier — it's the
     * platform-managed fast/cheap default (see LlmClient), not a BYOK upsell like
     * the other four. Enforced here (not just hidden in the UI) so a downgraded
     * tenant, or a custom-typed model name, can't bypass the gate.
     */
    private const PLAN_PROVIDERS = [
        'starter' => ['google', 'deepseek', 'groq'],
        'pro' => ['google', 'deepseek', 'groq', 'anthropic'],
        'business' => ['google', 'deepseek', 'groq', 'anthropic', 'openai'],
    ];

    /**
     * Monthly cap on direct-LLM calls per tenant (LLM Usage Billing / Tenant LLM
     * Limits, spec ЭТАП 1.4). Mirrors resources/js/lib/plans.ts' Plan.aiUsageLimit.
     * null = unlimited. Counts ai_runs rows with a recorded provider (i.e. calls
     * that actually reached a direct LLM provider), not every AI run.
     */
    private const PLAN_AI_USAGE_LIMITS = [
        'starter' => 1000,
        'pro' => 5000,
        'business' => null,
    ];

    private function planAllowsProvider(Tenant $tenant, string $provider): bool
    {
        $plan = (string) Arr::get($tenant->settings ?? [], 'billing.plan', 'starter');
        $allowed = self::PLAN_PROVIDERS[$plan] ?? self::PLAN_PROVIDERS['starter'];

        return in_array($provider, $allowed, true);
    }

    private function usageLimitExceeded(Tenant $tenant): bool
    {
        $plan = (string) Arr::get($tenant->settings ?? [], 'billing.plan', 'starter');
        $limit = array_key_exists($plan, self::PLAN_AI_USAGE_LIMITS) ? self::PLAN_AI_USAGE_LIMITS[$plan] : self::PLAN_AI_USAGE_LIMITS['starter'];

        if ($limit === null) {
            return false;
        }

        $usedThisMonth = AiRun::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('provider')
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        return $usedThisMonth >= $limit;
    }

    /**
     * @return array{text: string, provider: string, model: string, tokens_in: ?int, tokens_out: ?int, latency_ms: int, cost_usd: ?float}|null
     */
    private function directLlmReply(AiAgent $agent, Conversation $conversation, Message $message, bool $isFirstMessage): ?array
    {
        if (! $agent->model || ! $agent->tenant) {
            return null;
        }

        $provider = $this->llm->providerForModel($agent->model);

        if (! $provider || $this->llm->apiKey($agent->tenant, $provider) === '') {
            return null;
        }

        if (! $this->planAllowsProvider($agent->tenant, $provider)) {
            return null;
        }

        if ($this->usageLimitExceeded($agent->tenant)) {
            return null;
        }

        $systemPrompt = implode("\n\n", array_filter([
            "You are the CRM AI assistant for this company. Never identify as OpenAI, ChatGPT, DeepSeek, or a generic language model — answer as the company's own assistant.",
            'Answer naturally and helpfully in the same language the customer wrote in. If a question is about the company itself but you don\'t have the specific detail, ask one short clarifying question or say an operator will follow up.',
            "You only discuss this company's own services, booking, pricing and policies. If the customer asks something with no connection to the company at all (general knowledge, trivia, news, other businesses, coding help, or any other off-topic request), do NOT answer that question — do not provide the requested fact or information under any circumstances, even briefly. Instead, politely say that's outside what you can help with here, and steer the conversation back to the company's services. Never answer the off-topic question first and redirect after.",
            "Reply with ONLY the message you want the customer to read — plain conversational text. Never include headers or labels like 'Intent:', 'Summary:', 'Draft reply:' or 'Handoff:', and never analyze the request before answering it; that analysis is done separately and is not part of your output.",
            $agent->instructions ? 'Agent instructions: '.$agent->instructions : '',
            'Business profile:'."\n".$this->dify->businessProfile($agent),
            'Knowledge base:'."\n".$this->dify->knowledgeContext($agent),
            $isFirstMessage ? "This is the customer's first message in this conversation — begin your reply with a brief natural greeting stating the company name (and phone number if useful), then answer their question." : '',
        ], fn (string $part): bool => trim($part) !== ''));

        $userPrompt = implode("\n\n", array_filter([
            'Recent messages:'."\n".$this->dify->recentMessages($conversation),
            'Customer message:'."\n".$message->body,
        ], fn (string $part): bool => trim($part) !== ''));

        $completion = $this->llm->complete($agent->tenant, $provider, $agent->model, $systemPrompt, $userPrompt);

        if ($completion === null) {
            return null;
        }

        $completion['text'] = $this->sanitizeReplyText($completion['text']);

        return $completion + ['provider' => $provider, 'model' => $agent->model];
    }

    /**
     * Defense in depth: some models (deepseek-chat in particular) keep echoing an internal
     * Intent/Summary/Draft-reply/Handoff analysis format despite the system prompt asking for
     * plain conversational text — observed leaking verbatim to real customers over Telegram.
     * If that shape slips through anyway, pull out just the actual reply text.
     */
    private function sanitizeReplyText(string $text): string
    {
        $text = trim($text);

        if (preg_match('/\*\*(?:Draft reply|Reply draft)\s*:?\*\*\s*\n(.+?)(?:\n+\s*---|\n+\s*\*\*Handoff|\z)/is', $text, $matches)) {
            return trim($matches[1]);
        }

        return $text;
    }

    private function withReplyText(AiDecision $decision, string $replyText): AiDecision
    {
        return new AiDecision(
            confidence: $decision->confidence,
            intent: $decision->intent,
            summary: $decision->summary,
            nextAction: $decision->nextAction,
            handoffRequired: $decision->handoffRequired,
            replyText: $replyText,
        );
    }

    /**
     * @param array{text: string, provider: string, model: string, tokens_in: ?int, tokens_out: ?int, latency_ms: int, cost_usd: ?float}|null $usage
     */
    private function run(Tenant $tenant, AiAgent $agent, Conversation $conversation, Lead $lead, AiDecision $decision, string $engine, ?array $usage): AiRun
    {
        return AiRun::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'ai_agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'lead_id' => $lead->id,
            'status' => 'completed',
            'confidence' => $decision->confidence,
            'intent' => $decision->intent,
            'summary' => $decision->summary,
            'next_action' => $decision->nextAction,
            'started_at' => now(),
            'finished_at' => now(),
            'provider' => $usage['provider'] ?? null,
            'model' => $usage['model'] ?? $agent->model,
            'tokens_in' => $usage['tokens_in'] ?? null,
            'tokens_out' => $usage['tokens_out'] ?? null,
            'cost_usd' => $usage['cost_usd'] ?? null,
            'latency_ms' => $usage['latency_ms'] ?? null,
            'payload' => [
                'engine' => $engine,
                'model' => $agent->model,
                'handoff_required' => $decision->handoffRequired,
                'reply_text' => $decision->replyText,
            ],
        ]);
    }

    private function draftMessage(Tenant $tenant, Conversation $conversation, AiRun $run, AiDecision $decision, string $engine): ?Message
    {
        if ($decision->replyText === null || trim($decision->replyText) === '') {
            return null;
        }

        return Message::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'ai',
            'sender_name' => 'WERO AI',
            'body' => trim($decision->replyText),
            'external_id' => 'ai-run-'.$run->id,
            'sent_at' => now(),
            'meta' => [
                'draft' => true,
                'engine' => $engine,
                'ai_run_id' => $run->id,
                'next_action' => $decision->nextAction,
            ],
        ]);
    }

    /**
     * On a customer's very first message in a brand-new conversation, ask Telegram to
     * share their phone number via the native "request contact" reply-keyboard button
     * — so leads land in the CRM with a real phone number instead of just a Telegram
     * username. Tapping it sends a message back with a `contact` payload, handled by
     * TelegramWebhookController/ChatwootWebhookHandler::customer() (already reads
     * `sender.phone_number` and updates the existing customer). Best-effort: never
     * blocks the actual AI reply if Telegram rejects this or the token is missing.
     */
    private function requestContact(Tenant $tenant, Conversation $conversation): void
    {
        if ($conversation->channel?->provider !== 'telegram' || ! $conversation->external_id) {
            return;
        }

        $conversation->loadMissing('customer');

        if ($conversation->customer?->phone) {
            return;
        }

        try {
            $this->telegram->sendMessage(
                $tenant,
                str_replace('telegram-', '', (string) $conversation->external_id),
                'Поделитесь, пожалуйста, номером телефона, чтобы мы могли связаться с вами по записи 👇',
                replyMarkup: [
                    'keyboard' => [[['text' => '📱 Поделиться номером', 'request_contact' => true]]],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => true,
                ],
            );
        } catch (RuntimeException) {
        }
    }

    private function showTyping(Tenant $tenant, Conversation $conversation, bool $typing): void
    {
        if (! $this->autoReplyEnabled($tenant) || ! $conversation->external_id) {
            return;
        }

        try {
            if (in_array($conversation->channel?->provider, ['chatwoot', 'website'], true)) {
                $this->chatwoot->toggleTyping($tenant, (string) $conversation->external_id, $typing);
            }

            if ($typing && $conversation->channel?->provider === 'telegram') {
                $this->telegram->sendChatAction($tenant, str_replace('telegram-', '', (string) $conversation->external_id));
            }
        } catch (RuntimeException) {
        }
    }

    /**
     * Single switch for every channel — the per-Telegram override that used to live
     * on the Integrations card was removed (it was a second, confusing place to
     * control the same thing as the "Автоответ AI" toggle in the Chat header); this
     * is now the only place auto-reply is turned on or off.
     */
    private function autoReplyEnabled(Tenant $tenant): bool
    {
        return (bool) Arr::get($tenant->settings ?? [], 'integrations.chatwoot.auto_reply_enabled', false);
    }

    private function autoReply(Tenant $tenant, Conversation $conversation, ?Message $draft): void
    {
        if (! $draft || ! $conversation->external_id || ! $this->autoReplyEnabled($tenant)) {
            return;
        }

        if (! in_array($conversation->channel?->provider, ['chatwoot', 'website', 'telegram'], true)) {
            return;
        }

        $isTelegram = $conversation->channel?->provider === 'telegram';
        $chatId = str_replace('telegram-', '', (string) $conversation->external_id);

        try {
            $payload = $isTelegram
                ? $this->telegram->sendMessage($tenant, $chatId, $draft->body)
                : $this->chatwoot->sendOutgoingMessage($tenant, (string) $conversation->external_id, $draft->body);
        } catch (RuntimeException $error) {
            $draft->forceFill(['meta' => ($draft->meta ?? []) + ['auto_reply_failed' => $error->getMessage()]])->save();

            return;
        }

        // Telegram external_id keeps the same `telegram-{chatId}-{messageId}` shape used
        // everywhere else (webhook-ingested and operator-sent messages alike) — needed so
        // this AI-sent message can later be resolved as a reply target (see
        // ConversationReplyController::resolveTelegramReplyId()) or edited/deleted (see
        // MessageController::parseTelegramExternalId()).
        $externalId = $isTelegram
            ? 'telegram-'.$chatId.'-'.Arr::get($payload, 'result.message_id')
            : (string) (Arr::get($payload, 'id') ?? Arr::get($payload, 'payload.id') ?? $draft->external_id);

        $draft->forceFill([
            'external_id' => $externalId,
            'meta' => ($draft->meta ?? []) + ['draft' => false, 'auto_replied' => true, $conversation->channel?->provider => $payload],
        ])->save();
    }

    private function handoffTask(Tenant $tenant, Company $company, Lead $lead, Conversation $conversation, AiDecision $decision): CrmTask
    {
        return CrmTask::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'lead_id' => $lead->id, 'title' => 'AI handoff: '.$conversation->subject],
            ['status' => 'open', 'priority' => 'high', 'description' => $decision->summary]
        );
    }
}