<?php

namespace App\Support\Emergency;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;

/**
 * ЭТАП 16.11 — while a tenant's AI is down, new conversations get handed to a
 * human immediately instead of sitting unassigned. Simple fair round-robin: the
 * active operator/manager/owner with the fewest currently-open conversations
 * assigned to them. Reuses the existing conversations.assigned_user_id column
 * (already used for the "who's responsible for this customer" concept) — no new
 * schema needed.
 */
class AutoAssignmentService
{
    public function assignIfNeeded(Tenant $tenant, Conversation $conversation): void
    {
        if ($conversation->assigned_user_id) {
            return;
        }

        $agent = User::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', [User::ROLE_OPERATOR, User::ROLE_MANAGER, User::ROLE_OWNER])
            ->where('status', 'active')
            ->withCount(['assignedConversations as open_conversations_count' => function ($query): void {
                $query->where('status', '!=', 'resolved');
            }])
            ->orderBy('open_conversations_count')
            ->first();

        if (! $agent) {
            return;
        }

        $conversation->forceFill(['assigned_user_id' => $agent->id])->save();
    }
}
