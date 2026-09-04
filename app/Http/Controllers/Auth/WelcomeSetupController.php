<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First-run step for an admin-invited team member. The invite email
 * (TenantUserController::store() -> TeamInviteMail) deliberately carries no
 * password -- only a signed one-click link (acceptInvite() below), so
 * nothing sensitive ever sits in an inbox. Clicking it logs them in
 * directly and satisfies BOTH verification tiers at once (owning a link
 * mailed to your own address is the same proof EmailConfirmationLinkMail's
 * own verifyLink() already treats as sufficient) -- they land here with a
 * name the inviting owner typed for them and no password at all yet.
 * Gated purely by the `status` column, not a boolean flag: it's naturally
 * self-limiting (complete() 409s once status is no longer 'invited'), so
 * this doubles as the only place a user can ever set their password
 * without knowing a current one -- safe specifically because it's a
 * one-time window tied to a real invite, not a standing bypass.
 */
class WelcomeSetupController extends Controller
{
    public function acceptInvite(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::query()->find($id);

        abort_unless($user && hash_equals(sha1($user->email), $hash), 403);

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        if (! $user->email_link_confirmed_at) {
            $user->forceFill(['email_link_confirmed_at' => now()])->save();
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect($user->status === 'invited' ? '/welcome-setup' : '/app');
    }

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->status !== 'invited') {
            return redirect('/app');
        }

        return Inertia::render('WelcomeSetupPage', ['name' => $user->name, 'email' => $user->email]);
    }

    public function complete(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->status === 'invited', 409, 'Настройка аккаунта уже завершена.');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'password' => ['required', 'string', 'min:8', 'max:120'],
        ]);

        $update = ['status' => 'active', 'password' => Hash::make($data['password'])];

        if (! empty($data['name'])) {
            $update['name'] = $data['name'];
        }

        $user->update($update);

        return response()->json(['ok' => true]);
    }
}
