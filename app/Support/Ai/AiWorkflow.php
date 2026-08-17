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
use App\Support\Emergency\AutoAssignmentService;
use App\Support\Emergency\EmergencyStateManager;
use App\Support\Emergency\FallbackMessageResolver;
use App\Support\Integrations\PlatformSettings;
use App\Support\Integrations\TenantIntegrationSettings;
use App\Support\TelegramClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class AiWorkflow
{
    public function __construct(
        private readonly LocalConversationAnalyzer $localAnalyzer,
        private readonly DifyClient $dify,
        private readonly LlmClient $llm,
        private readonly ChatwootClient $chatwoot,
        private readonly TelegramClient $telegram,
        private readonly TenantIntegrationSettings $secrets,
        private readonly PlatformSettings $platform,
        private readonly EmergencyStateManager $emergency,
        private readonly FallbackMessageResolver $fallback,
        private readonly AutoAssignmentService $autoAssign,
    ) {
    }

    public function process(Tenant $tenant, Company $company, Conversation $conversation, Lead $lead, Message $message): array
    {
        $conversation->loadMissing('channel', 'customer');
        $provider = $conversation->channel?->provider;

        // Phone number is mandatory on Telegram/website before any real AI answer —
        // every customer message lands here until they give one; each just re-sends
        // the ask instead of answering. Not spammy in practice: ProcessAiReplyJob's
        // own "supersededBy" debounce already collapses a burst of ignored messages
        // down to one job run, so this fires once per burst, not once per message.
        if (in_array($provider, ['telegram', 'website'], true) && ! $conversation->customer?->phone) {
            $this->sendSystemMessage($tenant, $conversation, $provider, self::PHONE_REQUEST_TEXT, requestPhone: true);

            return ['agent' => null, 'run' => null, 'task' => null, 'draft_message' => null];
        }

        // NOT $conversation->wasRecentlyCreated — that Eloquent flag lives only on the
        // model instance that ran the insert, and this always runs from
        // ProcessAiReplyJob, which re-fetches the conversation via find() in a
        // separate process; it would silently be false here every time. Derived from
        // real DB state instead: true iff no earlier customer message exists.
        $isFirstMessage = ! Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', 'customer')
            ->where('id', '<', $message->id)
            ->exists();
        $agent = $this->agent($tenant, $company, $provider);
        if ($isFirstMessage) {
            $this->greetCustomer($tenant, $conversation);
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

        // ЭТАП 16.11 — while this tenant's AI is genuinely down (not just this one
        // handoff), new conversations go straight to a human instead of waiting on
        // the normal confidence-threshold handoff path above.
        if ($this->emergency->isEmergency($tenant)) {
            $this->autoAssign->assignIfNeeded($tenant, $conversation);
        }

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
                // A brand-new tenant (or one whose only agent got deleted) gets a
                // working model with zero setup — same reasoning as
                // AiAgentController::store()'s default, mirrored here since this
                // fallback bypasses that controller entirely.
                'model' => $this->platform->defaultModel(),
                'channels' => [],
                'settings' => ['mode' => config('services.dify.api_key') ? 'dify' : 'local-mvp'],
            ]
        );
    }

    private function decision(AiAgent $agent, Company $company, Conversation $conversation, Message $message, Lead $lead, bool $isFirstMessage): array
    {
        $tenant = $agent->tenant;
        $difyConfigured = $tenant !== null
            && $this->secrets->difyUrl($tenant) !== ''
            && $this->secrets->difyApiKey($tenant) !== '';

        $decision = $this->dify->decide($agent, $conversation, $message, $lead, $isFirstMessage);

        if ($decision) {
            $tenant && $this->emergency->recordAiOutcome($tenant, 'dify', $difyConfigured, false);

            return [$decision, 'dify', null];
        }

        $local = $this->localAnalyzer->analyze($conversation, $message, $lead, $agent->handoff_threshold, $company, $isFirstMessage);

        $llmProvider = $agent->model ? $this->llm->providerForModel($agent->model) : null;
        $llmConfigured = $tenant !== null && $llmProvider !== null
            && $this->llm->apiKey($tenant, $llmProvider) !== ''
            && $this->planAllowsProvider($tenant, $llmProvider)
            && ! $this->usageLimitExceeded($tenant);

        $llmCompletion = $this->directLlmReply($agent, $conversation, $message, $isFirstMessage);

        if ($llmCompletion !== null) {
            $tenant && $this->emergency->recordAiOutcome($tenant, 'direct-llm', $difyConfigured, $llmConfigured);

            return [$this->withReplyText($local, $llmCompletion['text']), 'direct-llm', $llmCompletion];
        }

        if ($tenant) {
            $this->emergency->recordAiOutcome($tenant, 'local-mvp', $difyConfigured, $llmConfigured);
        }

        // Genuine outage (Dify and/or the assigned model's provider actually
        // configured but failing) — the customer never sees local-mvp's raw canned
        // text in this case, and the conversation is forced to a human handoff.
        // A tenant that simply never configured anything keeps the normal local-mvp
        // behavior untouched (not an incident).
        if ($tenant && ($difyConfigured || $llmConfigured)) {
            $local = $this->withReplyText($local, $this->fallback->resolve($tenant, $message));
            $local = new AiDecision(
                confidence: $local->confidence,
                intent: $local->intent,
                summary: $local->summary,
                nextAction: 'handoff_operator',
                handoffRequired: true,
                replyText: $local->replyText,
            );
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

    private const DEFAULT_GREETING = 'Здравствуйте! Рады видеть вас снова 👋 Чем можем помочь?';

    private const PHONE_REQUEST_TEXT = 'Поделитесь, пожалуйста, номером телефона, чтобы мы могли связаться с вами по записи 📱';

    /**
     * On a customer's very first message, once we already know their phone (either
     * this call only happens after process()'s mandatory-phone gate has already
     * passed, or — same method, same result — they were already a known customer
     * from a past conversation, see ChatwootWebhookHandler::customer()'s phone/
     * email/name matching): send a plain welcome-back greeting. Telegram-only
     * before this; now also covers 'website' widget conversations.
     */
    private function greetCustomer(Tenant $tenant, Conversation $conversation): void
    {
        $provider = $conversation->channel?->provider;

        if (! in_array($provider, ['telegram', 'website'], true) || ! $conversation->external_id) {
            return;
        }

        $greeting = trim((string) Arr::get($conversation->channel?->settings ?? [], 'welcome_message')) ?: self::DEFAULT_GREETING;
        $this->sendSystemMessage($tenant, $conversation, $provider, $greeting);
    }

    /**
     * Sends a message that isn't a reply to anything the customer said — a proactive
     * greeting or phone nudge — through the channel's native API (Telegram) or, for
     * the widget (which has no such API; the "channel" is the CRM itself), straight
     * into the Message table so the widget's own poll picks it up. Persisted as a
     * real Message row either way so it's visible in the CRM transcript, not just
     * sent out silently (Telegram's old requestContact() never did this — the ask
     * only ever showed up in the customer's Telegram app, not here).
     */
    private function sendSystemMessage(Tenant $tenant, Conversation $conversation, string $provider, string $body, bool $requestPhone = false): void
    {
        $externalId = 'widget-system-'.$conversation->id.'-'.Str::random(8);

        if ($provider === 'telegram') {
            $chatId = str_replace('telegram-', '', (string) $conversation->external_id);

            try {
                $payload = $this->telegram->sendMessage(
                    $tenant,
                    $chatId,
                    $body,
                    replyMarkup: $requestPhone ? [
                        'keyboard' => [[['text' => '📱 Поделиться номером', 'request_contact' => true]]],
                        'resize_keyboard' => true,
                        'one_time_keyboard' => true,
                    ] : null,
                );
            } catch (RuntimeException) {
                return;
            }

            $externalId = 'telegram-'.$chatId.'-'.Arr::get($payload, 'result.message_id');
        }

        Message::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'ai',
            'body' => $body,
            'external_id' => $externalId,
            'sent_at' => now(),
            'meta' => ['system' => true, 'request_phone' => $requestPhone],
        ]);
    }

    private function showTyping(Tenant $tenant, Conversation $conversation, bool $typing): void
    {
        if (! $this->autoReplyEnabled($tenant) || ! $conversation->external_id) {
            return;
        }

        try {
            // 'website' deliberately excluded — a widget conversation has no external
            // platform to show a typing indicator on (see autoReply()'s matching branch).
            if ($conversation->channel?->provider === 'chatwoot') {
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
     * is now the only place auto-reply is turned on or off. The 3-state distinction
     * (off/priority/always) only matters for ProcessAiReplyJob's active-viewer gate —
     * here we only care whether it's off at all.
     */
    private function autoReplyEnabled(Tenant $tenant): bool
    {
        return $this->secrets->autoReplyMode($tenant) !== 'off';
    }

    private function autoReply(Tenant $tenant, Conversation $conversation, ?Message $draft): void
    {
        if (! $draft || ! $conversation->external_id || ! $this->autoReplyEnabled($tenant)) {
            return;
        }

        $provider = $conversation->channel?->provider;

        if (! in_array($provider, ['chatwoot', 'website', 'telegram'], true)) {
            return;
        }

        // A widget conversation has no external platform to push to — the message
        // row (already created as $draft) is delivered to the browser purely by the
        // widget polling WidgetController::index(), so "sending" here is a no-op;
        // just mark the draft as delivered like every other successful branch below.
        if ($provider === 'website') {
            $draft->forceFill(['meta' => ($draft->meta ?? []) + ['draft' => false, 'auto_replied' => true]])->save();

            return;
        }

        $isTelegram = $provider === 'telegram';
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