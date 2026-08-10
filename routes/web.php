<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\SessionController;
use App\Support\Dashboard\DashboardData;
use Illuminate\Http\Request;
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

Route::get('/', fn () => Inertia::render('HomePage'))->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->name('login.store');
    Route::get('/register', [SessionController::class, 'register'])->name('register');
    Route::post('/register', [SessionController::class, 'signup'])->name('register.store');
    Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
});

Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');

$dashboardPage = static fn (
    Request $request,
    DashboardData $dashboard,
    string $component,
) => Inertia::render($component, [
    'bootstrap' => $dashboard->forUser($request->user()),
]);

Route::middleware('auth')->group(function () use ($dashboardPage): void {
    Route::get('/app', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'OverviewPage'))->name('dashboard');
    Route::get('/inbox', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'InboxPage'))->name('inbox');
    Route::get('/leads', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'LeadsPage'))->name('leads');
    Route::get('/customers', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'CustomerProfilePage'))->name('customers');
    Route::get('/contacts', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'ContactsPage'))->name('contacts');
    Route::get('/ai', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'AiPage'))->name('ai');
    Route::get('/knowledge', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'KnowledgePage'))->name('knowledge');
    Route::get('/analytics', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'AnalyticsPage'))->name('analytics');
    Route::get('/integrations', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'IntegrationsPage'))->name('integrations');
    Route::get('/team', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'TeamPage'))->name('team');
    Route::get('/billing', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'BillingPage'))->name('billing');
    Route::get('/settings', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'SettingsPage'))->name('settings');
});