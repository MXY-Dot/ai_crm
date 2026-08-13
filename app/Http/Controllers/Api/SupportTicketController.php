<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SupportTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tickets = SupportTicket::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->with(['messages' => fn ($q) => $q->reorder('created_at', 'desc')->limit(1)])
            ->orderByDesc('last_message_at')
            ->get()
            ->map(fn (SupportTicket $ticket) => $this->summarize($ticket));

        return response()->json(['data' => $tickets]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string'],
        ]);

        $user = $request->user();

        $ticket = SupportTicket::query()->create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'subject' => $data['subject'],
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        SupportMessage::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'is_admin' => false,
            'body' => $data['body'],
        ]);

        $this->notifyAdmins($ticket, $data['body']);

        return response()->json($this->detail($ticket->fresh(['messages.user'])), 201);
    }

    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorizeTenant($request, $ticket);

        return response()->json($this->detail($ticket->load('messages.user')));
    }

    public function storeMessage(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorizeTenant($request, $ticket);

        $data = $request->validate(['body' => ['required', 'string']]);
        $user = $request->user();

        SupportMessage::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'is_admin' => false,
            'body' => $data['body'],
        ]);

        $ticket->forceFill(['status' => 'open', 'last_message_at' => now()])->save();

        $this->notifyAdmins($ticket, $data['body']);

        return response()->json($this->detail($ticket->fresh(['messages.user'])));
    }

    private function authorizeTenant(Request $request, SupportTicket $ticket): void
    {
        abort_unless($ticket->tenant_id === $request->user()->tenant_id, 403);
    }

    private function notifyAdmins(SupportTicket $ticket, string $preview): void
    {
        User::query()->where('role', User::ROLE_SUPER_ADMIN)->get()->each(
            fn (User $admin) => $admin->notify(new AppNotification(
                'support_ticket',
                'Тикет техподдержки: '.$ticket->subject,
                Str::limit($preview, 140),
                '/super-admin/support/'.$ticket->id,
            ))
        );
    }

    private function summarize(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'last_message_at' => $ticket->last_message_at,
            'last_message_preview' => $ticket->messages->first()?->body,
            'created_at' => $ticket->created_at,
        ];
    }

    private function detail(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
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
