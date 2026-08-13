<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SuperAdminSupportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::query()->with(['messages' => fn ($q) => $q->reorder('created_at', 'desc')->limit(1)]);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where('subject', 'like', "%{$search}%");
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $tickets = $query->orderByDesc('last_message_at')->paginate(15)->withQueryString();

        $tenantIds = collect($tickets->items())->pluck('tenant_id')->unique();
        $tenants = Tenant::query()->with(['companies' => fn ($q) => $q->limit(1)])->whereIn('id', $tenantIds)->get()->keyBy('id');

        $userIds = collect($tickets->items())->pluck('user_id')->unique();
        $requesters = User::query()->whereIn('id', $userIds)->get(['id', 'name', 'email'])->keyBy('id');

        return response()->json([
            'data' => collect($tickets->items())->map(fn (SupportTicket $ticket) => $this->summarize($ticket, $tenants->get($ticket->tenant_id), $requesters->get($ticket->user_id))),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    public function show(SupportTicket $ticket): JsonResponse
    {
        $ticket->load('messages.user:id,name,avatar_path');
        $tenant = Tenant::query()->with(['companies' => fn ($q) => $q->limit(1)])->find($ticket->tenant_id);
        $requester = User::query()->find($ticket->user_id, ['id', 'name', 'email', 'avatar_path']);

        return response()->json($this->detail($ticket, $tenant, $requester));
    }

    public function storeMessage(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string']]);
        $admin = $request->user();

        SupportMessage::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $admin->id,
            'is_admin' => true,
            'body' => $data['body'],
        ]);

        $ticket->forceFill(['status' => 'answered', 'last_message_at' => now()])->save();

        $requester = User::query()->find($ticket->user_id);
        $requester?->notify(new AppNotification(
            'support_reply',
            'Новый ответ от техподдержки: '.$ticket->subject,
            Str::limit($data['body'], 140),
            '/support?ticket='.$ticket->id,
        ));

        $tenant = Tenant::query()->with(['companies' => fn ($q) => $q->limit(1)])->find($ticket->tenant_id);

        return response()->json($this->detail($ticket->fresh('messages.user'), $tenant, $requester));
    }

    public function updateStatus(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'answered', 'closed'])]]);
        $ticket->forceFill(['status' => $data['status']])->save();

        return response()->json(['ok' => true]);
    }

    public function announce(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        $users = User::query()->whereNotNull('tenant_id')->get();
        $users->each(fn (User $user) => $user->notify(new AppNotification('platform_update', $data['title'], $data['body'] ?? null)));

        return response()->json(['ok' => true, 'notified' => $users->count()]);
    }

    private function summarize(SupportTicket $ticket, ?Tenant $tenant, ?User $requester): array
    {
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'company_name' => $tenant?->companies->first()?->name ?? $tenant?->name,
            'requester' => $requester?->only(['id', 'name', 'email']),
            'last_message_at' => $ticket->last_message_at,
            'last_message_preview' => $ticket->messages->first()?->body,
            'created_at' => $ticket->created_at,
        ];
    }

    private function detail(SupportTicket $ticket, ?Tenant $tenant, ?User $requester): array
    {
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'company_name' => $tenant?->companies->first()?->name ?? $tenant?->name,
            'requester' => $requester?->only(['id', 'name', 'email', 'avatar_url']),
            'created_at' => $ticket->created_at,
            'messages' => $ticket->messages->map(fn (SupportMessage $m) => [
                'id' => $m->id,
                'body' => $m->body,
                'is_admin' => $m->is_admin,
                'author' => $m->user?->name,
                'created_at' => $m->created_at,
            ]),
        ];
    }
}
