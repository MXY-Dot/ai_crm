<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\EmailConfirmationLinkMail;
use App\Models\Company;
use App\Models\User;
use App\Notifications\EmailVerificationCode as EmailVerificationCodeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Two independent, sequential confirmations -- not two ways to set the same
 * flag (see EnsureEmailVerified for how each gates the app):
 *
 * 1. email_verified_at -- a 5-digit code, sent automatically right after
 *    signup (sendCode/verify below). Unlocks reading the whole app.
 * 2. email_link_confirmed_at -- a signed link, sent only when the user
 *    clicks "Подтвердить почту" on Settings (sendConfirmationLink/verifyLink
 *    below), i.e. strictly after (1). Unlocks writes everywhere except the
 *    account self-service Settings already needed to get here.
 */
class EmailVerificationController extends Controller
{
    private const CODE_TTL_MINUTES = 15;

    private const LINK_TTL_MINUTES = 30;

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

        $user->forceFill([
            'email_verified_at' => now(),
            'email_verification_code' => null,
            'email_verification_code_expires_at' => null,
        ])->save();

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

    public function sendConfirmationLink(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->email_link_confirmed_at, 409, 'Почта уже подтверждена по ссылке.');

        self::sendLink($user);

        return response()->json(['ok' => true]);
    }

    public function verifyLink(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user && $user->id === $id && hash_equals(sha1($user->email), $hash), 403);

        // A lost/expired code shouldn't leave someone stuck with no way in --
        // owning the link proves the same thing owning the code would have.
        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        if (! $user->email_link_confirmed_at) {
            $user->forceFill(['email_link_confirmed_at' => now()])->save();
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

        $user->notify(new EmailVerificationCodeNotification($code));
    }

    public static function sendLink(User $user): void
    {
        $signedUrl = URL::temporarySignedRoute(
            'verification.verify-link',
            now()->addMinutes(self::LINK_TTL_MINUTES),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        Mail::to($user->email)->send(new EmailConfirmationLinkMail($signedUrl));
    }

    private function nextPath(User $user): string
    {
        // Reading works everywhere as soon as the code is done (see
        // EnsureEmailVerified) -- no need to detour through /profile first,
        // the link-confirm banner there is one click away from any page.
        $company = $user->tenant_id ? Company::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->first() : null;

        return ($company && ($company->business_type_id || $company->business_type_other)) ? '/app' : '/onboarding';
    }
}
