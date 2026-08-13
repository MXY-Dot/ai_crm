<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
    $conversation = Conversation::withoutGlobalScopes()->find($conversationId);

    if (! $conversation) {
        return false;
    }

    return $user->isSuperAdmin() || (int) $user->tenant_id === (int) $conversation->tenant_id;
});

Broadcast::channel('tenant.{tenantId}.conversations', function (User $user, int $tenantId) {
    return $user->isSuperAdmin() || (int) $user->tenant_id === $tenantId;
});
