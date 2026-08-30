<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Newest first is the DB order (latest()), but an unread urgent/high
        // notification from an hour ago is more worth surfacing at the top than
        // a just-arrived low-priority one — sort by priority within the fetched
        // page rather than re-querying, since it's already capped at 30 rows.
        $priorityRank = array_flip(array_reverse(AppNotification::PRIORITIES));

        $notifications = $request->user()->notifications()->latest()->limit(30)->get()
            ->sortBy(fn (DatabaseNotification $n) => $priorityRank[$n->data['priority'] ?? 'normal'] ?? $priorityRank['normal'])
            ->values();

        return response()->json([
            'data' => $notifications->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'type' => $n->data['type'] ?? 'system',
                'title' => $n->data['title'] ?? '',
                'body' => $n->data['body'] ?? null,
                'action_url' => $n->data['action_url'] ?? null,
                'priority' => $n->data['priority'] ?? 'normal',
                'read_at' => $n->read_at,
                'created_at' => $n->created_at,
            ]),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, DatabaseNotification $notification): JsonResponse
    {
        abort_unless($notification->notifiable_id === $request->user()->id && $notification->notifiable_type === get_class($request->user()), 403);

        if (! $notification->read_at) {
            $notification->markAsRead();
        }

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
