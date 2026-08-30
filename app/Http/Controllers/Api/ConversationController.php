<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeConversationJob;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationAnalysis;
use App\Models\ConversationPin;
use App\Models\ConversationRead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Chatwoot\ChatwootClient;
use App\Support\Inbox\ConversationStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Dedicated conversation list endpoint for the chat UI — search + real pagination,
 * unlike DashboardData::conversations() which is a fixed-12 snapshot baked into
 * the whole-app bootstrap. DashboardData is left untouched (still feeds the
 * initial Inertia page + the 10s app-wide poll for badges/counts elsewhere);
 * this is what the chat sidebar actually calls to list/search/paginate.
 */
class ConversationController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('view', $tenant);

        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        if (! $company) {
            return response()->json(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]]);
        }

        $search = trim((string) $request->query('search', ''));
        $label = trim((string) $request->query('label', ''));

        $query = Conversation::withoutGlobalScopes()
            ->with(['channel:id,provider,name', 'customer:id,name,phone,avatar_url', 'lead:id,title', 'assignedUser:id,name'])
            ->where('tenant_id', $tenant->id)
            ->where('company_id', $company->id);

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('subject', 'ilike', "%{$search}%")
                    ->orWhere('ai_summary', 'ilike', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$search}%")->orWhere('phone', 'ilike', "%{$search}%"));
            });
        }

        if ($label !== '') {
            $query->whereJsonContains('labels', $label);
        }

        $userId = (int) $request->user()->id;

        // Personal pin (like Telegram's own chat pinning) sorts a conversation to the
        // top of *this* operator's own list only — deliberately independent of
        // `assigned_user_id` ("who's responsible for this customer", a shared status
        // visible to everyone and untouched by pinning; see assign()/ConversationReplyController).
        // Each operator has their own pins on the exact same shared conversation rows.
        $paginator = $query
            ->orderByRaw(
                '(exists (select 1 from conversation_pins where conversation_pins.conversation_id = conversations.id and conversation_pins.user_id = ?)) desc',
                [$userId]
            )
            ->orderByDesc('last_message_at')
            ->paginate(self::PER_PAGE);

        $conversationIds = collect($paginator->items())->pluck('id');
        $unreadByConversation = $this->unreadCounts($conversationIds, $userId);
        $pinnedIds = ConversationPin::withoutGlobalScopes()
            ->whereIn('conversation_id', $conversationIds)
            ->where('user_id', $userId)
            ->pluck('conversation_id')
            ->all();

        $data = collect($paginator->items())->map(function (Conversation $conversation) use ($unreadByConversation, $pinnedIds): array {
            $row = $conversation->toArray();
            $row['unread_count'] = $unreadByConversation[$conversation->id] ?? 0;
            $row['is_pinned'] = in_array($conversation->id, $pinnedIds, true);

            return $row;
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Lightweight global count for the sidebar/notification-bell badges, which are
     * mounted app-wide (AppLayout) — unlike index() above, this deliberately doesn't
     * need a company lookup, pagination, or per-conversation breakdown, just one
     * number, so it stays cheap enough to poll from every page, not just /inbox.
     */
    public function unreadTotal(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('view', $tenant);

        $userId = (int) $request->user()->id;

        $total = Message::withoutGlobalScopes()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.tenant_id', $tenant->id)
            ->where('messages.sender_type', 'customer')
            ->whereRaw(
                'messages.id > coalesce((select last_read_message_id from conversation_reads where conversation_reads.conversation_id = messages.conversation_id and conversation_reads.user_id = ?), 0)',
                [$userId]
            )
            ->count();

        return response()->json(['total' => $total]);
    }

    /**
     * Manual claim/release — distinct from the automatic "first replier claims it"
     * behaviour in ConversationReplyController, which only ever sets an *unclaimed*
     * conversation and never overwrites an existing owner. This is an explicit,
     * deliberate override: an operator can claim an unclaimed conversation, steal
     * one from another operator, or release their own — always visible to everyone
     * via the same `assigned_user` field. ЭТАП 10.4 — this used to have no separate
     * "who did this" tracking beyond that visible field; now audited too, since a
     * stolen/released assignment is exactly the kind of action worth a record.
     */
    public function assign(Request $request, Conversation $conversation, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);
        abort_unless((int) $conversation->tenant_id === (int) $tenant->id, 404);

        $data = $request->validate(['assigned_user_id' => ['nullable', 'integer']]);
        $userId = $data['assigned_user_id'] ?? null;

        // Claiming/releasing for yourself needs no extra check — Gate::authorize()
        // above already established you're allowed to act in this tenant, and that's
        // true for super_admin too even though their own `users.tenant_id` is null
        // (they're not scoped to any single tenant by design). Only a *different*
        // target user needs the tenant-membership check — the UI doesn't expose
        // assigning to someone else yet, but the endpoint supports it generically.
        if ($userId !== null && $userId !== (int) $request->user()->id) {
            $exists = User::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('id', $userId)->exists();

            if (! $exists) {
                throw ValidationException::withMessages(['assigned_user_id' => 'User does not belong to this tenant.']);
            }
        }

        $previousUserId = $conversation->assigned_user_id;
        $conversation->forceFill(['assigned_user_id' => $userId])->save();
        $audit->record('conversation.assigned', $conversation, ['assigned_user_id' => $userId], ['assigned_user_id' => $previousUserId], $request);

        return response()->json($conversation->fresh()->load('assignedUser:id,name'));
    }

    /** ЭТАП 13.6 — the first thing that has ever actually written Conversation.status = 'closed'; the label already existed in the frontend with nothing behind it. */
    public function resolve(Conversation $conversation, TenantContext $context, ChatwootClient $chatwoot): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);
        abort_unless((int) $conversation->tenant_id === (int) $tenant->id, 404);

        $conversation->forceFill(['status' => ConversationStatus::CLOSED, 'resolved_at' => now()])->save();

        AnalyzeConversationJob::dispatch($conversation->id);

        $this->pushToChatwoot($tenant, $conversation->loadMissing('channel'), fn () => $chatwoot->resolveConversation($tenant, (string) $conversation->external_id));

        return response()->json($conversation->fresh(['channel', 'customer', 'lead']));
    }

    /** ТЗ «Отчётность...» раздел 14 — AI-разбор конкретного диалога (см. ConversationAnalyzer). Null body if not analyzed yet (too recent / still open / analysis pending). */
    public function analysis(Conversation $conversation, TenantContext $context): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('view', $tenant);
        abort_unless((int) $conversation->tenant_id === (int) $tenant->id, 404);

        $analysis = ConversationAnalysis::query()->where('conversation_id', $conversation->id)->first();

        return response()->json($analysis);
    }

    /** ЭТАП 3.7 — freeform operator-managed labels on a conversation; see Conversation::addLabel() for the AI-side auto-add counterpart. */
    public function labels(Request $request, Conversation $conversation, TenantContext $context, AuditLogger $audit, ChatwootClient $chatwoot): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);
        abort_unless((int) $conversation->tenant_id === (int) $tenant->id, 404);

        $data = $request->validate(['labels' => ['present', 'array'], 'labels.*' => ['string', 'max:60']]);
        $labels = array_values(array_unique($data['labels']));

        $previous = $conversation->labels;
        $conversation->forceFill(['labels' => $labels])->save();
        $audit->record('conversation.labels_updated', $conversation, ['labels' => $labels], ['labels' => $previous], $request);

        $this->pushToChatwoot($tenant, $conversation->loadMissing('channel'), fn () => $chatwoot->setLabels($tenant, (string) $conversation->external_id, $labels));

        return response()->json($conversation->fresh(['channel', 'customer', 'lead']));
    }

    /**
     * ЭТАП 3.10 — best-effort push-back to Chatwoot, only for channels actually
     * routed through it (see ЭТАП 2 — whatsapp/instagram/facebook go via Chatwoot,
     * telegram/website never do and have no Chatwoot-side conversation at all).
     * Unlike ConversationReplyController::send() (where a failed send means the
     * customer never got a reply, and must surface), the WERO-side action here
     * already succeeded and is the source of truth — a failed push (guaranteed
     * right now, since no tenant has Chatwoot configured) is logged, not thrown.
     */
    private function pushToChatwoot(Tenant $tenant, Conversation $conversation, callable $push): void
    {
        if (! in_array($conversation->channel?->provider, ['whatsapp', 'instagram', 'facebook'], true) || ! $conversation->external_id) {
            return;
        }

        try {
            $push();
        } catch (RuntimeException $error) {
            Log::warning('Chatwoot push-back failed', ['tenant_id' => $tenant->id, 'conversation_id' => $conversation->id, 'error' => $error->getMessage()]);
        }
    }

    /** Personal pin — see the `pins()` migration/model docblock. Purely per-user, no effect on `assigned_user_id`. */
    public function pin(Conversation $conversation, TenantContext $context, Request $request): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('view', $tenant);
        abort_unless((int) $conversation->tenant_id === (int) $tenant->id, 404);

        ConversationPin::withoutGlobalScopes()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['ok' => true, 'is_pinned' => true]);
    }

    public function unpin(Conversation $conversation, TenantContext $context, Request $request): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('view', $tenant);
        abort_unless((int) $conversation->tenant_id === (int) $tenant->id, 404);

        ConversationPin::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['ok' => true, 'is_pinned' => false]);
    }

    public function markRead(Conversation $conversation, TenantContext $context, Request $request): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('view', $tenant);
        abort_unless((int) $conversation->tenant_id === (int) $tenant->id, 404);

        $lastMessageId = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->max('id');

        ConversationRead::withoutGlobalScopes()->updateOrCreate(
            ['conversation_id' => $conversation->id, 'user_id' => $request->user()->id],
            ['tenant_id' => $tenant->id, 'last_read_message_id' => $lastMessageId]
        );

        return response()->json(['ok' => true]);
    }

    /**
     * One grouped query with a correlated subquery per row instead of one query
     * per conversation — unread = genuinely incoming customer messages (not our
     * own operator replies, and not the AI's auto-sent replies — those are
     * already-handled outgoing content, not something waiting on a human) newer
     * than this user's last-read mark for that thread.
     *
     * @param \Illuminate\Support\Collection<int, int> $conversationIds
     */
    private function unreadCounts($conversationIds, int $userId): array
    {
        if ($conversationIds->isEmpty()) {
            return [];
        }

        return Message::withoutGlobalScopes()
            ->whereIn('conversation_id', $conversationIds)
            ->where('sender_type', 'customer')
            ->whereRaw(
                'id > coalesce((select last_read_message_id from conversation_reads where conversation_reads.conversation_id = messages.conversation_id and conversation_reads.user_id = ?), 0)',
                [$userId]
            )
            ->selectRaw('conversation_id, count(*) as unread')
            ->groupBy('conversation_id')
            ->pluck('unread', 'conversation_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
