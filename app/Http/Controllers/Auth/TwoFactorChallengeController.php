<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return Inertia::render('TwoFactorChallengePage');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);

        $userId = $request->session()->get('login.id');
        abort_unless($userId, 419, 'Сессия входа истекла, войдите заново.');

        $user = User::query()->findOrFail($userId);

        abort_unless($this->codeIsValid($user, $data['code']), 422, 'Неверный код.');

        Auth::login($user, (bool) $request->session()->get('login.remember'));
        $request->session()->forget(['login.id', 'login.remember']);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    private function codeIsValid(User $user, string $code): bool
    {
        if ($user->two_factor_secret && (new Google2FA())->verifyKey($user->two_factor_secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $normalized = strtoupper(trim($code));

        if (! in_array($normalized, $codes, true)) {
            return false;
        }

        $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$normalized]))])->save();

        return true;
    }
}
