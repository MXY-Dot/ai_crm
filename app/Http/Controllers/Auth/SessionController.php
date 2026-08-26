<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlatformTelegramNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function create(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('LoginPage', [
            'login' => ['email' => 'owner@gravity.test', 'password' => 'password'],
            'plan' => request('plan', 'starter'),
        ]);
    }

    public function register(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('RegisterPage', [
            'plan' => request('plan', 'starter'),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $remember = $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $user = Auth::user();

        if ($user->two_factor_enabled) {
            Auth::logout();
            $request->session()->put('login.id', $user->id);
            $request->session()->put('login.remember', $remember);

            return response()->json(['two_factor' => true]);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard'));
    }

    public function signup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'workspace' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'plan' => ['nullable', 'in:starter,growth,pro'],
        ]);

        $tenant = Tenant::query()->create([
            'name' => $data['workspace'],
            'slug' => $this->tenantSlug($data['workspace']),
            'status' => 'inactive',
            'trial_ends_at' => null,
            'settings' => ['billing' => ['plan' => $data['plan'] ?? 'starter']],
        ]);

        Company::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $data['workspace'],
            'industry' => 'service',
        ]);

        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'owner',
            'status' => 'active',
            'last_login_at' => now(),
        ]);

        PlatformTelegramNotifier::notify(
            'Новая компания зарегистрирована: '.$tenant->name.PHP_EOL.
            'Владелец: '.$user->name.' ('.$user->email.')'.PHP_EOL.
            'Статус: неактивна, ожидает проверки модератором.'
        );

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route("onboarding");
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function tenantSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $i = 2;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}