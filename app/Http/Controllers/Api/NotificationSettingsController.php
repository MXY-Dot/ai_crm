<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ТЗ раздел 18 — per-user Telegram linking so notifications can actually
 * reach a staff member's own Telegram, through the tenant's already-
 * connected bot (see TenantTelegramChannel). The code lives in cache, not a
 * table -- purely a short-lived (10 min) handshake token, nothing worth
 * persisting once consumed or expired.
 */
class NotificationSettingsController extends Controller
{
    private const CODE_TTL_MINUTES = 10;

    public function telegramLinkCode(Request $request, TelegramClient $telegram): JsonResponse
    {
        $user = $request->user();
        $tenant = Tenant::query()->find($user->tenant_id);
        abort_unless($tenant, 404);

        try {
            $me = $telegram->getMe($tenant);
        } catch (RuntimeException $error) {
            return response()->json(['message' => 'Telegram-бот компании ещё не подключён: '.$error->getMessage()], 422);
        }

        $code = strtoupper(Str::random(6));
        Cache::put('telegram_link:'.$code, $user->id, now()->addMinutes(self::CODE_TTL_MINUTES));

        return response()->json([
            'code' => $code,
            'bot_username' => $me['username'] ?? null,
            'expires_in_minutes' => self::CODE_TTL_MINUTES,
        ]);
    }

    public function telegramUnlink(Request $request): JsonResponse
    {
        $request->user()->forceFill(['telegram_chat_id' => null])->save();

        return response()->json(['ok' => true]);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json(['telegram_linked' => (bool) $request->user()->telegram_chat_id]);
    }
}
