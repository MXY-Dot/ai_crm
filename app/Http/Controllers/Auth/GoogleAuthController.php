<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        if (! config('services.google.client_id') || ! config('services.google.redirect')) {
            return redirect()->route('login')->withErrors(['google' => 'Google OAuth is not configured yet.']);
        }

        $request->session()->put('google_oauth_state', $state = Str::random(32));

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! hash_equals((string) $request->session()->pull('google_oauth_state'), (string) $request->query('state'))) {
            return redirect()->route('login')->withErrors(['google' => 'Google OAuth state is invalid.']);
        }

        try {
            $profile = $this->profile((string) $request->query('code'));
        } catch (RuntimeException $error) {
            return redirect()->route('login')->withErrors(['google' => $error->getMessage()]);
        }

        $email = (string) Arr::get($profile, 'email');
        $name = (string) (Arr::get($profile, 'name') ?: $email);

        if ($email === '') {
            return redirect()->route('login')->withErrors(['google' => 'Google did not return an email address.']);
        }

        $user = User::query()->where('email', $email)->first() ?? $this->createUser($name, $email);
        $user->forceFill(['last_login_at' => now()])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    private function profile(string $code): array
    {
        if ($code === '') {
            throw new RuntimeException('Google OAuth code is missing.');
        }

        $token = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        if (! $token->successful()) {
            throw new RuntimeException('Google token exchange failed.');
        }

        $profile = Http::withToken((string) $token->json('access_token'))->get('https://www.googleapis.com/oauth2/v3/userinfo');

        if (! $profile->successful()) {
            throw new RuntimeException('Google profile request failed.');
        }

        return $profile->json();
    }

    private function createUser(string $name, string $email): User
    {
        $tenant = Tenant::query()->create([
            'name' => $name.' Workspace',
            'slug' => $this->tenantSlug($name),
            'status' => 'trial',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['billing' => ['plan' => 'starter']],
        ]);

        Company::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $tenant->name,
            'industry' => 'service',
        ]);

        return User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => $email,
            'password' => Str::password(32),
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    private function tenantSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'google-workspace';
        $slug = $base;
        $i = 2;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}