<?php

namespace App\Support\Inbox;

use App\Jobs\ProcessAiReplyJob;
use App\Models\AiRun;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Customers\CustomerMatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChatwootWebhookHandler
{
    public function __construct(private readonly CustomerMatcher $customers)
    {
    }

    /**
     * @return array{company: Company, channel: Channel, customer: Customer, lead: Lead, conversation: Conversation, message: Message}
     */
    private function ingest(Tenant $tenant, array $payload): array
    {
        return DB::transaction(function () use ($tenant, $payload): array {
            $company = $this->company($tenant);
            $channel = $this->channel($tenant, $company, $payload);

            // A returning conversation is the strongest identity signal available --
            // stronger than re-matching this message's phone/email/name against the
            // whole customer table, which can drift (a Telegram display name changed,
            // a phone came formatted with a leading '+' this time but not last time)
            // and silently reassign or duplicate the customer on every single message.
            // Resolve it first and reuse its existing customer when there is one.
            $existingCustomerId = Conversation::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('company_id', $company->id)
                ->where('external_id', $this->externalConversationId($payload))
                ->value('customer_id');

            $customer = $this->customer($tenant, $company, $payload, $existingCustomerId);
            $lead = $this->lead($tenant, $company, $customer, $payload);
            $conversation = $this->conversation($tenant, $company, $channel, $customer, $lead, $payload);
            $message = $this->message($tenant, $conversation, $payload);

            return compact('company', 'channel', 'customer', 'lead', 'conversation', 'message');
        });
    }

    /**
     * Ingests the incoming message synchronously (so it's committed and visible to
     * the CRM's own polling immediately), then hands AI processing off to a queued
     * job — see ProcessAiReplyJob for why: an inline LLM call here used to block the
     * webhook response, and with it the customer's message even appearing in the CRM,
     * for the several seconds the AI reply took to generate.
     */
    public function handle(Tenant $tenant, array $payload, bool $runAi = true): array
    {
        ['company' => $company, 'channel' => $channel, 'customer' => $customer, 'lead' => $lead, 'conversation' => $conversation, 'message' => $message]
            = $this->ingest($tenant, $payload);

        if (! $message->wasRecentlyCreated) {
            return $this->duplicateResult($channel, $customer, $lead, $conversation, $message);
        }

        if ($runAi) {
            // Short delay so a burst of messages sent seconds apart collapses into
            // one reply instead of one-per-message — see ProcessAiReplyJob::handle()'s
            // "supersededBy" check, which this delay exists to give a chance to fire.
            ProcessAiReplyJob::dispatch($tenant->id, $company->id, $conversation->id, $lead->id, $message->id)
                ->delay(now()->addSeconds(2));
        }

        $task = $this->task($tenant, $company, $lead->fresh(), $conversation->fresh(), $payload);

        return [
            'channel' => $channel->fresh(),
            'customer' => $customer->fresh(),
            'lead' => $lead->fresh(),
            'conversation' => $conversation->fresh(['channel', 'customer', 'lead']),
            'message' => $message->fresh(),
            'ai_agent' => null,
            'ai_run' => null,
            'task' => $task?->fresh(),
            'duplicate' => false,
        ];
    }

    /**
     * ЭТАП 3.9 — Chatwoot event types with no message body (conversation_status_changed,
     * conversation_resolved, assignee_changed, label_added/removed, contact_updated,
     * etc.) used to funnel through handle() -> message(), which threw a 422 the moment
     * body was empty. This is the lightweight counterpart for those: sync what's
     * actually documented (conversation.status) on an existing conversation, and
     * otherwise just acknowledge — never guesses at undocumented payload shapes.
     */
    public function handleConversationEvent(Tenant $tenant, array $payload): array
    {
        $company = $this->company($tenant);
        $externalId = $this->externalConversationId($payload);

        $conversation = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('company_id', $company->id)
            ->where('external_id', $externalId)
            ->first();

        if (! $conversation) {
            return ['ignored' => true, 'reason' => 'conversation_not_found'];
        }

        $status = Arr::get($payload, 'conversation.status');

        if ($status) {
            $conversation->forceFill(['status' => ConversationStatus::fromChatwoot((string) $status)])->save();
        }

        return ['ignored' => false, 'conversation' => $conversation->fresh(['channel', 'customer', 'lead'])];
    }

    private function duplicateResult(Channel $channel, Customer $customer, Lead $lead, Conversation $conversation, Message $message): array
    {
        $latestRun = AiRun::withoutGlobalScopes()
            ->with('agent')
            ->where('tenant_id', $message->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->latest('finished_at')
            ->first();

        return [
            'channel' => $channel->fresh(),
            'customer' => $customer->fresh(),
            'lead' => $lead->fresh(),
            'conversation' => $conversation->fresh(['channel', 'customer', 'lead']),
            'message' => $message->fresh(),
            'ai_agent' => $latestRun?->agent,
            'ai_run' => $latestRun,
            'task' => null,
            'duplicate' => true,
        ];
    }

    private function company(Tenant $tenant): Company
    {
        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        if (! $company) {
            throw ValidationException::withMessages(['tenant' => 'Tenant has no company configured.']);
        }

        return $company;
    }

    private function channel(Tenant $tenant, Company $company, array $payload): Channel
    {
        $provider = $this->string($payload, ['channel.provider', 'conversation.channel', 'provider'], 'chatwoot');
        $name = $this->string($payload, ['channel.name', 'inbox.name'], ucfirst($provider).' Inbox');

        return Channel::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'provider' => $provider, 'name' => $name],
            ['status' => 'connected', 'external_id' => $this->string($payload, ['inbox.id', 'channel.external_id']), 'last_synced_at' => now()]
        );
    }

    private function customer(Tenant $tenant, Company $company, array $payload, ?int $existingCustomerId = null): Customer
    {
        $name = $this->string($payload, ['sender.name', 'contact.name', 'customer.name'], 'Unknown customer');
        $phone = $this->string($payload, ['sender.phone_number', 'sender.phone', 'contact.phone_number', 'customer.phone']);
        $email = $this->string($payload, ['sender.email', 'contact.email', 'customer.email']);
        $source = $this->string($payload, ['channel.provider', 'provider'], 'chatwoot');
        $avatarUrl = $this->string($payload, ['sender.avatar_url', 'contact.avatar_url']);

        // Reuse the conversation's already-linked customer when there is one, just
        // refreshing it with whatever new signal this message carries (e.g. a phone
        // number shared partway through the chat) -- never re-derive identity from
        // scratch for a message that already belongs to a known conversation.
        if ($existingCustomerId) {
            $existing = Customer::withoutGlobalScopes()->find($existingCustomerId);

            if ($existing) {
                $data = array_filter([
                    'name' => $name !== 'Unknown customer' ? $name : null,
                    'phone' => $phone,
                    'email' => $email,
                    'avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
                ], fn ($value) => $value !== null);

                if ($data !== []) {
                    $existing->update($data);
                }

                return $existing;
            }
        }

        return $this->customers->findOrCreate($tenant, $company, $name, $phone, $email, $source, ['chatwoot' => true], $avatarUrl !== '' ? $avatarUrl : null);
    }

    private function lead(Tenant $tenant, Company $company, Customer $customer, array $payload): Lead
    {
        $title = $this->string($payload, ['lead.title', 'conversation.subject', 'conversation.display_id'], 'Chatwoot conversation');
        $conversationId = $this->externalConversationId($payload);
        $source = $this->string($payload, ['channel.provider', 'provider'], 'chatwoot');

        return Lead::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'source' => $source, 'title' => $title],
            [
                'customer_id' => $customer->id,
                'status' => 'new',
                'score' => $this->int($payload, ['lead.score', 'ai.score'], 50),
                'ai_summary' => $this->string($payload, ['ai.summary', 'conversation.ai_summary'], 'Lead created from Chatwoot conversation '.$conversationId.'.'),
            ]
        );
    }

    private function conversation(Tenant $tenant, Company $company, Channel $channel, Customer $customer, Lead $lead, array $payload): Conversation
    {
        $externalId = $this->externalConversationId($payload);
        $subject = $this->string($payload, ['conversation.subject', 'conversation.display_id', 'lead.title'], 'Chatwoot conversation '.$externalId);

        return Conversation::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'external_id' => $externalId],
            [
                'channel_id' => $channel->id,
                'customer_id' => $customer->id,
                'lead_id' => $lead->id,
                'subject' => $subject,
                'status' => ConversationStatus::fromChatwoot($this->string($payload, ['conversation.status'], 'open')),
                'priority' => $this->string($payload, ['conversation.priority'], 'normal'),
                'last_message_at' => now(),
                'ai_summary' => $this->string($payload, ['ai.summary', 'conversation.ai_summary']),
            ]
        );
    }

    private function message(Tenant $tenant, Conversation $conversation, array $payload): Message
    {
        $body = $this->string($payload, ['message.content', 'message.body', 'content', 'body'], '');

        if ($body === '') {
            throw ValidationException::withMessages(['message.content' => 'Message content is required.']);
        }

        $externalId = $this->string($payload, ['message.id', 'message.external_id', 'id'], sha1($conversation->id.'|'.$body));
        $replyToExternalId = $this->string($payload, ['message.reply_to_external_id']);
        $replyToMessageId = $replyToExternalId
            ? Message::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('conversation_id', $conversation->id)
                ->where('external_id', $replyToExternalId)
                ->value('id')
            : null;
        $attachment = Arr::get($payload, 'message.attachment');
        $meta = ['event' => $this->string($payload, ['event'], 'message_created')];

        if ($attachment) {
            $meta['attachment'] = $attachment;
        }

        return Message::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'conversation_id' => $conversation->id, 'external_id' => $externalId],
            [
                'sender_type' => $this->senderType($payload),
                'sender_name' => $this->string($payload, ['sender.name', 'contact.name', 'message.sender.name']),
                'body' => $body,
                'reply_to_message_id' => $replyToMessageId,
                'sent_at' => now(),
                'meta' => $meta,
            ]
        );
    }

    private function task(Tenant $tenant, Company $company, Lead $lead, Conversation $conversation, array $payload): ?CrmTask
    {
        $shouldCreate = $this->bool($payload, ['handoff.required', 'ai.handoff', 'needs_handoff'], false);

        if (! $shouldCreate) {
            return null;
        }

        return CrmTask::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'lead_id' => $lead->id, 'title' => 'Review Chatwoot handoff: '.$conversation->subject],
            ['status' => 'open', 'priority' => 'high', 'description' => $conversation->ai_summary]
        );
    }

    private function senderType(array $payload): string
    {
        $type = $this->string($payload, ['message.sender.type', 'sender.type', 'sender_type'], 'customer');

        return in_array($type, ['customer', 'operator', 'ai', 'system'], true) ? $type : 'customer';
    }

    private function externalConversationId(array $payload): string
    {
        return (string) $this->string($payload, ['conversation.id', 'conversation.external_id', 'conversation_id'], 'chatwoot-'.sha1(json_encode($payload)));
    }

    private function string(array $payload, array $keys, ?string $default = null): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);

            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return $default;
    }

    private function int(array $payload, array $keys, int $default): int
    {
        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);

            if ($value !== null && $value !== '') {
                return max(0, min(100, (int) $value));
            }
        }

        return $default;
    }

    private function bool(array $payload, array $keys, bool $default): bool
    {
        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);

            if ($value !== null) {
                return filter_var($value, FILTER_VALIDATE_BOOL);
            }
        }

        return $default;
    }
}