<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeamMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 1-on-1 internal team chat -- separate from the customer-facing Inbox
 *  (conversations/messages), no channel/lead/AI concept, just colleagues. */
class TeamMessageController extends Controller
{
    public function threads(Request $request): JsonResponse
    {
        $user = $request->user();

        $colleagues = User::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'avatar_path', 'last_seen_at']);

        $threads = $colleagues->map(function (User $colleague) use ($user): array {
            $last = TeamMessage::query()
                ->where(function ($q) use ($user, $colleague): void {
                    $q->where('sender_id', $user->id)->where('recipient_id', $colleague->id);
                })
                ->orWhere(function ($q) use ($user, $colleague): void {
                    $q->where('sender_id', $colleague->id)->where('recipient_id', $user->id);
                })
                ->latest('id')
                ->first();

            return [
                'user' => $colleague,
                'last_message' => $last?->body,
                'last_message_at' => $last?->created_at,
                'unread_count' => TeamMessage::query()
                    ->where('sender_id', $colleague->id)
                    ->where('recipient_id', $user->id)
                    ->whereNull('read_at')
                    ->count(),
            ];
        })->sortByDesc(fn (array $thread) => $thread['last_message_at'] ?? '0')->values();

        return response()->json($threads);
    }

    public function messages(Request $request, User $colleague): JsonResponse
    {
        $user = $request->user();
        abort_unless($colleague->tenant_id === $user->tenant_id, 404);

        $messages = TeamMessage::query()
            ->with('sender:id,name,avatar_path')
            ->where(function ($q) use ($user, $colleague): void {
                $q->where('sender_id', $user->id)->where('recipient_id', $colleague->id);
            })
            ->orWhere(function ($q) use ($user, $colleague): void {
                $q->where('sender_id', $colleague->id)->where('recipient_id', $user->id);
            })
            ->orderBy('id')
            ->get();

        TeamMessage::query()
            ->where('sender_id', $colleague->id)
            ->where('recipient_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json($messages);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $recipient = User::query()->findOrFail($data['recipient_id']);
        abort_unless($recipient->tenant_id === $user->tenant_id, 404);

        $message = TeamMessage::query()->create([
            'sender_id' => $user->id,
            'recipient_id' => $recipient->id,
            'body' => $data['body'],
        ]);

        return response()->json($message->load('sender:id,name,avatar_path'), 201);
    }
}
