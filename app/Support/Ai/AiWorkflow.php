<?php

namespace App\Support\Ai;

use App\Jobs\NotifyVipContactJob;
use App\Models\AiAgent;
use App\Models\AiRun;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\CrmTask;
use App\Models\Lead;
use App\Models\LanguageExample;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Chatwoot\ChatwootClient;
use App\Support\Emergency\AutoAssignmentService;
use App\Support\Emergency\EmergencyStateManager;
use App\Support\Emergency\FallbackMessageResolver;
use App\Support\Integrations\PlatformSettings;
use App\Support\Integrations\TenantIntegrationSettings;
use App\Support\Language\LanguageDetector;
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
        private readonly LanguageDetector $languageDetector,
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
        // ЭТАП 12.2 — a VIP customer's first message in a conversation gets bumped
        // to high priority and a heads-up to staff, same as the spec's own
        // example ("VIP-клиент написал... Приоритет: высокий"). Once per
        // conversation (gated on $isFirstMessage), not once per message.
        if ($isFirstMessage && in_array($conversation->customer?->vip_status, ['vip', 'top_vip'], true)) {
            $conversation->forceFill(['priority' => 'high'])->save();
            NotifyVipContactJob::dispatch($tenant->id, $conversation->id);
        }

        $agent = $this->agent($tenant, $company, $provider);
        if ($isFirstMessage) {
            $this->greetCustomer($tenant, $conversation);
        }
        $this->showTyping($tenant, $conversation, true);
        [$decision, $engine, $usage] = $this->decision($agent, $company, $conversation, $message, $lead, $isFirstMessage);
        $run = $this->run($tenant, $agent, $conversation, $lead, $message, $decision, $engine, $usage);
        $draft = $this->draftMessage($tenant, $conversation, $run, $decision, $engine);
        $this->autoReply($tenant, $conversation, $draft);
        $this->showTyping($tenant, $conversation, false);

        $lead->forceFill([
            'score' => $decision->confidence,
            'ai_summary' => $decision->summary,
            'next_action' => $decision->nextAction,
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

        $selfThrottled = false;
        $llmCompletion = $this->directLlmReply($agent, $conversation, $message, $isFirstMessage, $selfThrottled);

        if ($llmCompletion !== null) {
            $tenant && $this->emergency->recordAiOutcome($tenant, 'direct-llm', $difyConfigured, $llmConfigured);

            return [$this->withReplyText($local, $llmCompletion['text']), 'direct-llm', $llmCompletion];
        }

        // ЭТАП 15.11 — a self-imposed rate-limit skip (LlmClient's own outbound
        // ceiling, or the provider's HTTP 429) is not an outage: the provider is
        // fine, WERO just chose not to call it right now. $selfThrottled (set by
        // directLlmReply() above) is true only when every attempt actually made —
        // primary, and backup if one was genuinely usable and tried — failed
        // purely for that reason, never because of a real failure, an open
        // circuit, or an unconfigured/plan-disallowed backup.
        if ($tenant && ! $selfThrottled) {
            $this->emergency->recordAiOutcome($tenant, 'local-mvp', $difyConfigured, $llmConfigured);
        }

        // Genuine outage (Dify and/or the assigned model's provider actually
        // configured but failing) — the customer never sees local-mvp's raw canned
        // text in this case, and the conversation is forced to a human handoff.
        // A tenant that simply never configured anything keeps the normal local-mvp
        // behavior untouched (not an incident); neither does a self-throttled burst.
        if ($tenant && ! $selfThrottled && ($difyConfigured || $llmConfigured)) {
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
     * $wasThrottled is set true only when every attempt made (primary, and backup
     * if one was actually tried) failed purely because of LlmClient's self-imposed
     * rate limit (ЭТАП 15.11) — never because of a real failure, an open circuit
     * breaker, or a backup that simply wasn't configured/plan-allowed. Lets
     * decision() below tell a normal traffic burst apart from a genuine AI outage.
     *
     * @return array{text: string, provider: string, model: string, tokens_in: ?int, tokens_out: ?int, latency_ms: int, cost_usd: ?float}|null
     */
    private function directLlmReply(AiAgent $agent, Conversation $conversation, Message $message, bool $isFirstMessage, bool &$wasThrottled = false): ?array
    {
        $wasThrottled = false;

        if (! $agent->model || ! $agent->tenant) {
            return null;
        }

        $primaryProvider = $this->llm->providerForModel($agent->model);

        if (! $primaryProvider) {
            return null;
        }

        $systemPrompt = implode("\n\n", array_filter([
            "You are the CRM AI assistant for this company. Never identify as OpenAI, ChatGPT, DeepSeek, or a generic language model — answer as the company's own assistant.",
            'Answer naturally and helpfully in the same language the customer wrote in. If a question is about the company itself but you don\'t have the specific detail, ask one short clarifying question or say an operator will follow up.',
            "You only discuss this company's own services, booking, pricing and policies. If the customer asks something with no connection to the company at all (general knowledge, trivia, news, other businesses, coding help, or any other off-topic request), do NOT answer that question — do not provide the requested fact or information under any circumstances, even briefly. Instead, politely say that's outside what you can help with here, and steer the conversation back to the company's services. Never answer the off-topic question first and redirect after.",
            "Reply with ONLY the message you want the customer to read — plain conversational text. Never include headers or labels like 'Intent:', 'Summary:', 'Draft reply:' or 'Handoff:', and never analyze the request before answering it; that analysis is done separately and is not part of your output.",
            $agent->instructions ? 'Agent instructions: '.$agent->instructions : '',
            // ЭТАП 9.3 — AI Goal Engine: shapes what the assistant steers the
            // conversation toward, without a separate LLM call — just a stronger
            // instruction on the same reply-generating request.
            $agent->goal ? 'Your goal for this conversation is to guide the customer toward: '.$agent->goal.'. Keep this in mind when deciding what to suggest next, without being pushy.' : '',
            // ЭТАП 9.6 — objection handling: acknowledge the concern, reframe the
            // value, then offer a concrete next step, instead of just answering
            // flatly or immediately deferring to an operator.
            "If the customer raises a price objection, hesitates (\"I'll think about it\"), or compares you to a competitor, acknowledge their concern genuinely first, briefly reinforce the value or a relevant detail, and end with one concrete, low-pressure next step (e.g. a question, a smaller offer, or an invitation to continue when ready). Don't just repeat the price or dismiss the concern.",
            // ЭТАП 6.2/6.4/6.5 — customers here often write colloquial Tajik
            // (Cyrillic), a mix of Tajik and Russian in the same message, or
            // Tajik typed in Latin transliteration. Treat all of these as normal
            // input: never ask the customer what language they're writing in or
            // express confusion about mixed/transliterated text — just respond
            // naturally, matching their language and register.
            'Customers in this region commonly write in colloquial Tajik (Cyrillic script), a mix of Tajik and Russian within one message, or Tajik transliterated into Latin letters. Treat all of these as completely normal — never ask what language the customer is writing in, never comment on mixed or transliterated spelling, and reply naturally in the same language/mix the customer used.',
            $this->languageExamples($agent->tenant),
            'Business profile:'."\n".$this->dify->businessProfile($agent),
            'Knowledge base:'."\n".$this->dify->knowledgeContext($agent),
            $isFirstMessage ? "This is the customer's first message in this conversation — begin your reply with a brief natural greeting stating the company name (and phone number if useful), then answer their question." : '',
            // ЭТАП 12.2's own example: a VIP customer gets a warmer, priority tone
            // instead of the generic "передано менеджеру" — the reason string is
            // the same one shown to staff in the VIP customers table.
            in_array($conversation->customer?->vip_status, ['vip', 'top_vip'], true)
                ? 'This is a VIP customer ('.$conversation->customer->vip_reason.') — greet them warmly by name if known and treat their request as a priority.'
                : '',
        ], fn (string $part): bool => trim($part) !== ''));

        $userPrompt = implode("\n\n", array_filter([
            'Recent messages:'."\n".$this->dify->recentMessages($conversation),
            'Customer message:'."\n".$message->body,
        ], fn (string $part): bool => trim($part) !== ''));

        $primaryThrottled = false;
        $completion = $this->attemptCompletion($agent->tenant, $primaryProvider, $agent->model, $systemPrompt, $userPrompt, $primaryThrottled);
        $wasThrottled = $primaryThrottled;

        // ЭТАП 15.5 — the primary provider (whichever the agent's own model maps
        // to) came back empty: either its circuit is open (HealthMonitor), the
        // call itself failed, or it's self-throttled. Try the platform's
        // designated backup provider once, with its own default model, before
        // giving up to local-mvp — same plan gating as the primary, so a
        // downgraded tenant can't get free access to a premium backup during an
        // outage.
        if ($completion === null) {
            $backupProvider = $this->platform->backupLlmProvider();

            if ($backupProvider && $backupProvider !== $primaryProvider) {
                $backupModel = $this->platform->defaultModelFor($backupProvider);
                $backupThrottled = false;
                $completion = $this->attemptCompletion($agent->tenant, $backupProvider, $backupModel, $systemPrompt, $userPrompt, $backupThrottled);

                if ($completion !== null) {
                    $completion['used_backup'] = true;
                }

                // Both the primary and this actually-attempted backup must have
                // been throttled-only for the overall result to still count as
                // "just throttled" — if the backup failed for a real reason
                // (or wasn't throttled but genuinely errored), this is a real gap.
                $wasThrottled = $primaryThrottled && $backupThrottled;
            } else {
                // No usable backup to try at all — whether this counts as
                // "only throttled" rests entirely on the primary's own outcome.
                $wasThrottled = $primaryThrottled;
            }
        }

        if ($completion === null) {
            return null;
        }

        $completion['text'] = $this->sanitizeReplyText($completion['text']);

        return $completion;
    }

    /**
     * ЭТАП 6.3 — tenant-provided reference examples (customer message → good
     * reply), empty by default: WERO doesn't invent example dialogue content,
     * companies add their own verified examples via Настройки → AI. No caching —
     * a handful of rows per tenant, cheap enough per request.
     */
    private function languageExamples(?Tenant $tenant): string
    {
        if (! $tenant) {
            return '';
        }

        $examples = LanguageExample::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->inRandomOrder()
            ->limit(3)
            ->get();

        if ($examples->isEmpty()) {
            return '';
        }

        $formatted = $examples->map(fn (LanguageExample $example): string => 'Customer: '.$example->customer_message."\nGood reply: ".$example->good_reply)->implode("\n\n");

        return "Example good responses from this company's own past conversations — match this tone and phrasing style where relevant:\n".$formatted;
    }

    /**
     * @return array{text: string, provider: string, model: string, tokens_in: ?int, tokens_out: ?int, latency_ms: int, cost_usd: ?float}|null
     */
    private function attemptCompletion(Tenant $tenant, string $provider, string $model, string $systemPrompt, string $userPrompt, bool &$wasThrottled = false): ?array
    {
        $wasThrottled = false;

        if ($this->llm->apiKey($tenant, $provider) === '') {
            return null;
        }

        if (! $this->planAllowsProvider($tenant, $provider)) {
            return null;
        }

        if ($this->usageLimitExceeded($tenant)) {
            return null;
        }

        if ($this->llm->isThrottled($provider)) {
            $wasThrottled = true;

            return null;
        }

        $completion = $this->llm->complete($tenant, $provider, $model, $systemPrompt, $userPrompt);

        if ($completion === null) {
            return null;
        }

        return $completion + ['provider' => $provider, 'model' => $model];
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
     * @param array{text: string, provider: string, model: string, tokens_in: ?int, tokens_out: ?int, latency_ms: int, cost_usd: ?float, used_backup?: bool}|null $usage
     */
    private function run(Tenant $tenant, AiAgent $agent, Conversation $conversation, Lead $lead, Message $message, AiDecision $decision, string $engine, ?array $usage): AiRun
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
                'used_backup' => $usage['used_backup'] ?? false,
                // ЭТАП 6.1 — best-effort tag, not a confident classification (see
                // LanguageDetector's own docblock). Kept in the existing payload
                // JSON rather than a new messages column, purely for visibility/
                // analytics — nothing downstream branches on this value.
                'detected_language' => $this->languageDetector->detect($message->body),
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