<?php

namespace App\Jobs;

use App\Http\Controllers\Api\ConversationTypingController;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Ai\AiWorkflow;
use App\Support\Integrations\TenantIntegrationSettings;
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
        private readonly int $waitedForMutex = 0,
    ) {
    }

    public function handle(AiWorkflow $workflow, TenantIntegrationSettings $settings): void
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

        // An operator already has this conversation open right now — let them handle
        // it. Checked here (right before generating) rather than earlier, so a message
        // that arrived while nobody was looking still gets an AI reply as normal, and
        // this only kicks in for the case that actually matters: staff is already
        // present with the customer when the message lands. Skipped entirely in
        // 'always' mode, where the AI replies no matter who's looking at the chat.
        $autoReplyMode = $settings->autoReplyMode($tenant);

        if ($autoReplyMode !== 'always' && ConversationTypingController::hasActiveViewer($conversation->id)) {
            return;
        }

        $cacheKey = ConversationTypingController::aiGeneratingCacheKey($conversation->id);

        // The supersededBy check above only looks at messages that existed the
        // instant THIS job started — it doesn't cover two messages sent close
        // enough together (a few seconds apart, well within how fast a real
        // person types a follow-up) that each dispatch+2s-delay lands in a gap
        // where the other genuinely didn't exist yet. Real bug this fixes: two
        // such messages both passed that check independently and each produced
        // its own full reply — visible to the customer as two near-identical
        // answers back to back. This second gate catches that: if a generation
        // for this conversation is already in flight, don't start a competing
        // one — wait for it to finish, since AiWorkflow::process()'s "recent
        // messages" context will pick up this message anyway once it runs.
        // Capped at 2 re-dispatches (~10s) so a stuck/slow generation can't
        // loop forever; after that, proceeds anyway rather than dropping the
        // message.
        if (Cache::get($cacheKey) && $this->waitedForMutex < 2) {
            static::dispatch($this->tenantId, $this->companyId, $this->conversationId, $this->leadId, $this->messageId, $this->waitedForMutex + 1)
                ->delay(now()->addSeconds(5));

            return;
        }

        Cache::put($cacheKey, true, now()->addSeconds($this->timeout));

        try {
            $workflow->process($tenant, $company, $conversation, $lead, $message);
        } finally {
            Cache::forget($cacheKey);
        }
    }
}
