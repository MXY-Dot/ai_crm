<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\CompanyNotificationMail;
use App\Models\Company;
use App\Models\Tenant;
use App\Support\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
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

    /** Настройки компании → Уведомления — "Проверить" buttons. Sends directly to the clicking user, bypassing every preference/frequency gate in AppNotification, since the whole point is testing the channel itself. */
    public function testEmail(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->email, 422, 'У вашего аккаунта не указан email.');

        $company = $user->tenant_id ? Company::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->first() : null;

        Mail::to($user->email)->send(new CompanyNotificationMail(
            title: 'Тестовое уведомление',
            body: 'Если вы видите это письмо — email-уведомления в WERO настроены и работают.',
            actionUrl: null,
            companyName: $company?->name,
            urgent: false,
        ));

        return response()->json(['ok' => true]);
    }

    public function testTelegram(Request $request, TelegramClient $telegram): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->telegram_chat_id, 422, 'Сначала подключите свой Telegram в профиле.');

        $tenant = Tenant::query()->find($user->tenant_id);
        abort_unless($tenant, 404);

        try {
            $telegram->sendMessage($tenant, $user->telegram_chat_id, "Тестовое уведомление\n\nЕсли вы видите это сообщение — Telegram-уведомления в WERO настроены и работают.");
        } catch (RuntimeException $error) {
            return response()->json(['message' => 'Не удалось отправить сообщение: '.$error->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }
}
