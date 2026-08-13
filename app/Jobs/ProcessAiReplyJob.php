<?php

namespace App\Jobs;

use App\Http\Controllers\Api\ConversationTypingController;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Ai\AiWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Runs AiWorkflow::process() off the request/webhook thread. ChatwootWebhookHandler
 * used to call this inline inside the same DB transaction that creates the incoming
 * message — meaning the webhook (and therefore the customer's message becoming
 * visible in the CRM, which polls) sat blocked for however long the LLM call took
 * (~2-4s observed with DeepSeek). Dispatching this instead lets the customer's
 * message land immediately; the AI reply follows a few seconds later once this job
 * runs. Requires a `queue:work` process actually running (see deploy notes) —
 * QUEUE_CONNECTION=database jobs just sit in the `jobs` table otherwise.
 */
class ProcessAiReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        private readonly int $tenantId,
        private readonly int $companyId,
        private readonly int $conversationId,
        private readonly int $leadId,
        private readonly int $messageId,
    ) {
    }

    public function handle(AiWorkflow $workflow): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        $company = Company::withoutGlobalScopes()->find($this->companyId);
        $conversation = Conversation::withoutGlobalScopes()->find($this->conversationId);
        $lead = Lead::withoutGlobalScopes()->find($this->leadId);
        $message = Message::withoutGlobalScopes()->find($this->messageId);

        if (! $tenant || ! $company || ! $conversation || ! $lead || ! $message) {
            return;
        }

        // Debounce burst sends: this job is dispatched with a short delay (see
        // ChatwootWebhookHandler::handle()) specifically so that if the customer
        // fires off several messages in quick succession (very common — people
        // split a thought across 2-3 messages, or resend impatiently while
        // waiting), only the LAST one in the burst actually triggers a reply.
        // Every earlier message's job checks here whether a newer customer
        // message has shown up since it was dispatched and, if so, quietly
        // skips — the job for that newer message will run instead, and its
        // reply already has full context (AiWorkflow pulls recent history),
        // so nothing said in the skipped messages is lost. Without this, a
        // 3-message burst produced 3 separate, independently-generated (and
        // often redundant/contradictory) AI replies flooding the chat.
        $supersededBy = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', 'customer')
            ->where('id', '>', $message->id)
            ->exists();

        if ($supersededBy) {
            return;
        }

        $cacheKey = ConversationTypingController::aiGeneratingCacheKey($conversation->id);
        Cache::put($cacheKey, true, now()->addSeconds($this->timeout));

        try {
            $workflow->process($tenant, $company, $conversation, $lead, $message);
        } finally {
            Cache::forget($cacheKey);
        }
    }
}
