<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Support\Analytics\ConversationAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued so a conversation gets an AI analysis (outcome/quality/sentiment,
 * ТЗ раздел 3-6/14) within seconds of being resolved, instead of waiting for
 * the next hourly `conversations:analyze` sweep (still the safety net for
 * conversations that go quiet without an explicit resolve).
 */
class AnalyzeConversationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(private readonly int $conversationId)
    {
    }

    public function handle(ConversationAnalyzer $analyzer): void
    {
        $conversation = Conversation::withoutGlobalScopes()->find($this->conversationId);

        if ($conversation) {
            $analyzer->analyze($conversation);
        }
    }
}
