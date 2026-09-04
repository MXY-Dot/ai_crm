<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Notifications\EmailVerificationCode as EmailVerificationCodeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Two independent ways to set the same email_verified_at column: a 5-digit
 * code entered right after signup (the primary path, see VerifyEmailPage),
 * and a signed link mailed alongside it in the same message (a fallback for
 * a resend triggered from Settings, or if the code page was abandoned).
 * Either one satisfies EnsureEmailVerified for the rest of the app.
 */
class EmailVerificationController extends Controller
{
    private const CODE_TTL_MINUTES = 15;

    private const RESEND_COOLDOWN_SECONDS = 60;

    public function notice(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->email_verified_at) {
            return redirect($this->nextPath($user));
        }

        return Inertia::render('VerifyEmailPage', ['email' => $user->email]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        abort_if($user->email_verified_at, 409, 'Почта уже подтверждена.');

        $valid = $user->email_verification_code
            && hash_equals($user->email_verification_code, trim($data['code']))
            && $user->email_verification_code_expires_at
            && $user->email_verification_code_expires_at->isFuture();

        abort_unless($valid, 422, 'Неверный или устаревший код.');

        $this->markVerified($user);

        return response()->json(['ok' => true, 'redirect' => $this->nextPath($user)]);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->email_verified_at, 409, 'Почта уже подтверждена.');

        if ($user->email_verification_code_expires_at) {
            $sentAt = $user->email_verification_code_expires_at->clone()->subMinutes(self::CODE_TTL_MINUTES);
            abort_if($sentAt->addSeconds(self::RESEND_COOLDOWN_SECONDS)->isFuture(), 429, 'Подождите немного перед повторной отправкой.');
        }

        self::sendCode($user);

        return response()->json(['ok' => true]);
    }

    public function verifyLink(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user && $user->id === $id && hash_equals(sha1($user->email), $hash), 403);

        if (! $user->email_verified_at) {
            $this->markVerified($user);
        }

        return redirect($this->nextPath($user));
    }

    public static function sendCode(User $user): void
    {
        $code = (string) random_int(10000, 99999);
        $user->forceFill([
            'email_verification_code' => $code,
            'email_verification_code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ])->save();

        $signedUrl = URL::temporarySignedRoute(
            'verification.verify-link',
            now()->addMinutes(self::CODE_TTL_MINUTES),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $user->notify(new EmailVerificationCodeNotification($code, $signedUrl));
    }

    private function markVerified(User $user): void
    {
        $user->forceFill([
            'email_verified_at' => now(),
            'email_verification_code' => null,
            'email_verification_code_expires_at' => null,
        ])->save();
    }

    private function nextPath(User $user): string
    {
        $company = $user->tenant_id ? Company::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->first() : null;

        return ($company && ($company->business_type_id || $company->business_type_other)) ? '/app' : '/onboarding';
    }
}
