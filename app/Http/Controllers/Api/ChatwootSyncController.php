<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Chatwoot\ChatwootConversationSync;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ChatwootSyncController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, ChatwootConversationSync $sync): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);

        try {
            $result = $sync->sync($tenant);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['chatwoot' => $error->getMessage()]);
        }

        return response()->json(['ok' => true] + $result);
    }
}