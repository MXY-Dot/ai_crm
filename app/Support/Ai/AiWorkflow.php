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
        private readonly ChatwootClient $chatwoot,
        private readonly TelegramClient $telegram,
    ) {
    }

    public function process(Tenant $tenant, Company $company, Conversation $conversation, Lead $lead, Message $message): array
    {
        $agent = $this->agent($tenant, $company);
        $conversation->loadMissing('channel');
        $this->showTyping($tenant, $conversation, true);
        [$decision, $engine] = $this->decision($agent, $conversation, $message, $lead);
        $run = $this->run($tenant, $agent, $conversation, $lead, $decision, $engine);
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

    private function agent(Tenant $tenant, Company $company): AiAgent
    {
        return AiAgent::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'name' => 'Default Dify Assistant'],
            [
                'provider' => 'dify',
                'status' => 'active',
                'handoff_threshold' => 70,
                'instructions' => 'Classify intent, summarize the latest customer message, draft a helpful reply, and decide whether an operator handoff is needed.',
                'settings' => ['mode' => config('services.dify.api_key') ? 'dify' : 'local-mvp'],
            ]
        );
    }

    private function decision(AiAgent $agent, Conversation $conversation, Message $message, Lead $lead): array
    {
        $decision = $this->dify->decide($agent, $conversation, $message, $lead);

        if ($decision) {
            return [$decision, 'dify'];
        }

        return [$this->localAnalyzer->analyze($conversation, $message, $lead, $agent->handoff_threshold), 'local-mvp'];
    }

    private function run(Tenant $tenant, AiAgent $agent, Conversation $conversation, Lead $lead, AiDecision $decision, string $engine): AiRun
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
            'payload' => [
                'engine' => $engine,
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
            'sender_name' => $engine === 'dify' ? 'Dify AI' : 'Local AI',
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

    private function showTyping(Tenant $tenant, Conversation $conversation, bool $typing): void
    {
        if (! $this->autoReplyEnabled($tenant, (string) $conversation->channel?->provider) || ! $conversation->external_id) {
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

    private function autoReplyEnabled(Tenant $tenant, string $provider): bool
    {
        $settings = $tenant->settings ?? [];

        if ($provider === 'telegram') {
            return (bool) (Arr::get($settings, 'integrations.telegram.auto_reply_enabled') ?? Arr::get($settings, 'integrations.chatwoot.auto_reply_enabled', false));
        }

        return (bool) Arr::get($settings, 'integrations.chatwoot.auto_reply_enabled', false);
    }
    private function autoReply(Tenant $tenant, Conversation $conversation, ?Message $draft): void
    {
        if (! $draft || ! $conversation->external_id || ! $this->autoReplyEnabled($tenant, (string) $conversation->channel?->provider)) {
            return;
        }

        if (! in_array($conversation->channel?->provider, ['chatwoot', 'website', 'telegram'], true)) {
            return;
        }

        try {
            $payload = $conversation->channel?->provider === 'telegram'
                ? $this->telegram->sendMessage($tenant, str_replace('telegram-', '', (string) $conversation->external_id), $draft->body)
                : $this->chatwoot->sendOutgoingMessage($tenant, (string) $conversation->external_id, $draft->body);
        } catch (RuntimeException $error) {
            $draft->forceFill(['meta' => ($draft->meta ?? []) + ['auto_reply_failed' => $error->getMessage()]])->save();

            return;
        }

        $draft->forceFill([
            'external_id' => (string) (Arr::get($payload, 'id') ?? Arr::get($payload, 'payload.id') ?? Arr::get($payload, 'result.message_id') ?? $draft->external_id),
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