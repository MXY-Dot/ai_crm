<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\AppNotification;
use App\Support\Notifications\NotificationTypeBuckets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            // 'unread'/'read' also serve as the spec's "Новые"/"Решённые" — this
            // data model only has read/unread, not a separate resolved state.
            'status' => ['nullable', Rule::in(['all', 'unread', 'read', 'critical'])],
            'bucket' => ['nullable', Rule::in(array_keys(NotificationTypeBuckets::BUCKETS))],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = $request->user()->notifications();

        if (($data['status'] ?? 'all') === 'unread') {
            $query->whereNull('read_at');
        } elseif (($data['status'] ?? null) === 'read') {
            $query->whereNotNull('read_at');
        }

        $limit = $data['limit'] ?? 30;

        // 'critical' and the type-bucket filters read the JSON `data` blob --
        // stored as plain `text` in this table, not `jsonb`, so a SQL JSON-path
        // query isn't portable here. Filtered in PHP after a bounded fetch
        // instead: simplest correct approach for a table that's per-user and
        // never going to be large.
        $needsPhpFilter = ($data['status'] ?? null) === 'critical' || isset($data['bucket']);
        $candidates = $query->latest()->limit($needsPhpFilter ? 500 : $limit)->get();

        if (($data['status'] ?? null) === 'critical') {
            $candidates = $candidates->filter(fn (DatabaseNotification $n): bool => ($n->data['priority'] ?? 'normal') === 'urgent');
        }

        if (isset($data['bucket'])) {
            $types = NotificationTypeBuckets::BUCKETS[$data['bucket']];
            $candidates = $types === []
                ? collect()
                : $candidates->filter(fn (DatabaseNotification $n): bool => in_array($n->data['type'] ?? '', $types, true));
        }

        // Newest first is the DB order (latest()), but an unread urgent/high
        // notification from an hour ago is more worth surfacing at the top than
        // a just-arrived low-priority one — sort by priority within the fetched
        // page rather than re-querying.
        $priorityRank = array_flip(array_reverse(AppNotification::PRIORITIES));

        $notifications = $candidates
            ->sortBy(fn (DatabaseNotification $n) => $priorityRank[$n->data['priority'] ?? 'normal'] ?? $priorityRank['normal'])
            ->take($limit)
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
