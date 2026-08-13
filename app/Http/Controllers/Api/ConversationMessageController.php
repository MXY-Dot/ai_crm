<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Per-conversation message history, cursor-paginated by message id — replaces
 * relying on DashboardData's tenant-wide latest-24 snapshot for the chat thread.
 * `before` (a message id) loads the page of messages older than that id, for the
 * chat UI's "load older on scroll up". `after` loads everything newer than that
 * id (no limit — this is the polling realtime transport's catch-up query, see
 * resources/js/lib/chat/realtime.ts) — mutually exclusive with `before`; with
 * neither, returns the latest page (initial load).
 */
class ConversationMessageController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request, Conversation $conversation, TenantContext $context): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('view', $tenant);
        abort_unless((int) $conversation->tenant_id === (int) $tenant->id, 404);

        $before = $request->query('before');
        $after = $request->query('after');

        $query = Message::withoutGlobalScopes()
            ->with('replyTo:id,sender_type,sender_name,body,deleted_at')
            ->where('conversation_id', $conversation->id);

        if ($after) {
            $messages = (clone $query)->where('id', '>', (int) $after)->orderBy('id')->get();

            return response()->json(['data' => $messages, 'meta' => ['has_more' => false, 'oldest_id' => $messages->first()?->id]]);
        }

        if ($before) {
            $query->where('id', '<', (int) $before);
        }

        $messages = $query->orderByDesc('id')->limit(self::PER_PAGE)->get();
        $hasMore = $messages->count() === self::PER_PAGE;

        return response()->json([
            'data' => $messages->sortBy('id')->values(),
            'meta' => ['has_more' => $hasMore, 'oldest_id' => $messages->last()?->id],
        ]);
    }
}
