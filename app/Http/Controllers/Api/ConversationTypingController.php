<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Lightweight "is typing" presence for operators sharing a conversation —
 * there's no WebSocket transport yet (see resources/js/lib/chat/realtime.ts),
 * so this is a poll-friendly heartbeat: the composer calls `heartbeat` every
 * few keystrokes/seconds while focused, other viewers of the same conversation
 * poll `index`. Backed by the cache table (CACHE_STORE=database here) rather
 * than a new table — this is ephemeral presence data, not a record worth
 * persisting. Customer-side typing isn't wired in: Telegram's Bot API doesn't
 * expose inbound typing events, and Chatwoot's webhook payloads for it weren't
 * part of this batch — this endpoint is honestly only operator-to-operator today.
 */
class ConversationTypingController extends Controller
{
    private const TTL_SECONDS = 6;

    public function heartbeat(Request $request, Conversation $conversation, TenantContext $context): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('view', $tenant);
        abort_unless((int) $conversation->tenant_id === (int) $tenant->id, 404);

        $user = $request->user();
        $typers = $this->activeTypers($conversation->id);
        $typers[$user->id] = ['user_id' => $user->id, 'name' => $user->name, 'at' => now()->timestamp];

        Cache::put($this->cacheKey($conversation->id), $typers, now()->addSeconds(self::TTL_SECONDS * 3));

        return response()->json(['ok' => true]);
    }

    public function index(Conversation $conversation, TenantContext $context, Request $request): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('view', $tenant);
        abort_unless((int) $conversation->tenant_id === (int) $tenant->id, 404);

        $typers = $this->activeTypers($conversation->id);
        unset($typers[$request->user()->id]);

        return response()->json([
            'typers' => array_values($typers),
            'ai_generating' => Cache::get(self::aiGeneratingCacheKey($conversation->id), false),
        ]);
    }

    private function activeTypers(int $conversationId): array
    {
        $typers = Cache::get($this->cacheKey($conversationId), []);
        $cutoff = now()->timestamp - self::TTL_SECONDS;

        return array_filter($typers, fn (array $typer): bool => $typer['at'] >= $cutoff);
    }

    private function cacheKey(int $conversationId): string
    {
        return "chat:typing:{$conversationId}";
    }

    /** Shared with ProcessAiReplyJob, which sets/clears this flag around AiWorkflow::process(). */
    public static function aiGeneratingCacheKey(int $conversationId): string
    {
        return "chat:ai_generating:{$conversationId}";
    }
}
