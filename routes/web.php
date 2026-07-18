<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\SessionController;
use App\Support\Dashboard\DashboardData;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/chatwoot-vite/assets/{asset}', function (string $asset) {
    abort_if(str_contains($asset, '..'), 404);

    $baseUrl = rtrim((string) config('services.chatwoot.url', 'http://127.0.0.1:3000'), '/');
    $response = \Illuminate\Support\Facades\Http::timeout(10)
        ->get($baseUrl.'/vite-dev/assets/'.$asset);

    abort_unless($response->successful(), $response->status());

    return response($response->body(), 200)
        ->header('Content-Type', $response->header('Content-Type', 'text/javascript'))
        ->header('Cache-Control', 'no-store');
})->where('asset', '[A-Za-z0-9_.\/-]+');

Route::get('/', fn () => Inertia::render('Dashboard', [
    'bootstrap' => ['authMode' => 'landing'],
    'page' => 'overview',
]))->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->name('login.store');
    Route::get('/register', [SessionController::class, 'register'])->name('register');
    Route::post('/register', [SessionController::class, 'signup'])->name('register.store');
    Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
});

Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');

$dashboardView = fn (DashboardData $dashboard, string $page = 'overview') => Inertia::render('Dashboard', [
    'bootstrap' => $dashboard->forUser(request()->user()) + ['authMode' => 'dashboard'],
    'page' => $page,
]);

Route::get('/app', fn (DashboardData $dashboard) => $dashboardView($dashboard))->middleware('auth')->name('dashboard');
Route::get('/{page}', fn (string $page, DashboardData $dashboard) => $dashboardView($dashboard, $page))
    ->whereIn('page', ['inbox', 'leads', 'customers', 'crm', 'ai', 'knowledge', 'analytics', 'integrations', 'settings'])
    ->middleware('auth')
    ->name('dashboard.page');