<?php

namespace App\Support\Notifications;

/**
 * ТЗ раздел 17/18 — the single source of truth for the "Продажи"/"Жалобы"/
 * "Ошибки AI"/"Работа операторов" grouping, used both by
 * NotificationController (Центр уведомлений bucket filter) and
 * AppNotification/NotificationDigest (per-bucket channel routing) so the two
 * groupings can never drift apart.
 */
class NotificationTypeBuckets
{
    public const BUCKETS = [
        'sales' => ['lead_qualified', 'vip_contact', 'competitor_mentioned', 'large_order'],
        'complaints' => ['complaint', 'wants_manager'],
        'ai_errors' => ['handoff_needed', 'ai_knowledge_gap', 'repeated_problem'],
        'operators' => ['operator_idle', 'waiting_too_long'],
    ];

    public static function bucketFor(string $type): ?string
    {
        foreach (self::BUCKETS as $bucket => $types) {
            if (in_array($type, $types, true)) {
                return $bucket;
            }
        }

        return null;
    }
}
