<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\LocaleController;
use App\Http\Middleware\EnsurePageAccess;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantActive;
use App\Models\Channel;
use App\Models\Tenant;
use App\Support\Dashboard\DashboardData;
use App\Support\Business\ModuleRegistry;
use App\Models\BusinessType;
use App\Models\Company;
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

Route::get('/widget-test', fn () => view('widget-test', [
    'siteKey' => Channel::withoutGlobalScopes()->where('provider', 'website')->whereNotNull('external_id')->value('external_id'),
]));

Route::get('/', fn () => Inertia::render('HomePage'))->name('home');

// ТЗ /policy — Meta App Review requires a working Privacy Policy URL to publish
// the app; this is that page, public/unauthenticated, no dashboard chrome.
Route::get('/policy', fn () => Inertia::render('PrivacyPolicyPage'))->name('policy');

Route::post('/locale/{locale}', [LocaleController::class, 'update'])->name('locale.update');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->name('login.store');
    Route::get('/register', [SessionController::class, 'register'])->name('register');
    Route::post('/register', [SessionController::class, 'signup'])->name('register.store');
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->name('two-factor.challenge.store');
});

Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');

Route::get('/onboarding', function (Request $request) {
    $user = $request->user();
    $company = $user->tenant_id ? Company::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->first() : null;

    if ($company && ($company->business_type_id || $company->business_type_other)) {
        return redirect()->route('dashboard');
    }

    return Inertia::render('OnboardingPage', [
        'businessTypes' => BusinessType::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'key', 'name']),
        'modules' => ModuleRegistry::labels(),
    ]);
})->middleware('auth')->name('onboarding');

$dashboardPage = static fn (
    Request $request,
    DashboardData $dashboard,
    string $component,
) => Inertia::render($component, [
    'bootstrap' => $dashboard->forUser($request->user()),
]);

Route::middleware(['auth', EnsureTenantActive::class, EnsurePageAccess::class])->group(function () use ($dashboardPage): void {
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
    Route::get('/vip', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'VipCustomersPage'))->name('vip');
    Route::get('/campaigns', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'CampaignsPage'))->name('campaigns');
    Route::get('/ai', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'AiPage'))->name('ai');
    Route::get('/knowledge', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'KnowledgePage'))->name('knowledge');
    Route::get('/booking', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'BookingCalendarPage'))->name('booking');
    Route::get('/booking-settings', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'BookingSettingsPage'))->name('booking-settings');
    Route::get('/analytics', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'AnalyticsPage'))->name('analytics');
    Route::get('/notifications', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'NotificationCenterPage'))->name('notifications');
    Route::get('/integrations', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'IntegrationsPage'))->name('integrations');
    Route::get('/team', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'TeamPage'))->name('team');
    Route::get('/support', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'SupportPage'))->name('support');
    Route::get('/marketplace', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'IntegrationsCatalogPage'))->name('marketplace');
    Route::get('/billing', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'BillingPage'))->name('billing');
    Route::get('/settings', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'SettingsPage'))->name('settings');
    Route::get('/profile', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'ProfilePage'))->name('profile');
});

Route::middleware(['auth', EnsureSuperAdmin::class])->prefix('super-admin')->group(function (): void {
    $superAdminPage = static fn (Request $request, string $component) => Inertia::render($component, [
        'currentUser' => $request->user()?->only(['id', 'name', 'email', 'role', 'avatar_url']),
    ]);

    Route::get('/overview', fn (Request $request) => $superAdminPage($request, 'SuperAdminOverviewPage'))->name('super-admin.overview');
    Route::get('/analytics', fn (Request $request) => $superAdminPage($request, 'SuperAdminAnalyticsPage'))->name('super-admin.analytics');
    Route::get('/companies', fn (Request $request) => $superAdminPage($request, 'SuperAdminCompaniesPage'))->name('super-admin.companies');
    Route::get('/users', fn (Request $request) => $superAdminPage($request, 'SuperAdminUsersPage'))->name('super-admin.users');
    Route::get('/billing', fn (Request $request) => $superAdminPage($request, 'SuperAdminBillingPage'))->name('super-admin.billing');
    Route::get('/llm-providers', fn (Request $request) => $superAdminPage($request, 'SuperAdminLlmProvidersPage'))->name('super-admin.llm-providers');
    Route::get('/support', fn (Request $request) => $superAdminPage($request, 'SuperAdminSupportPage'))->name('super-admin.support');
    Route::get('/insights', fn (Request $request) => $superAdminPage($request, 'SuperAdminInsightsPage'))->name('super-admin.insights');
    Route::get('/business-modules', fn (Request $request) => $superAdminPage($request, 'SuperAdminBusinessModulesPage'))->name('super-admin.business-modules');
    Route::get('/language-quality', fn (Request $request) => $superAdminPage($request, 'SuperAdminLanguageQualityPage'))->name('super-admin.language-quality');
    Route::get('/incidents', fn (Request $request) => $superAdminPage($request, 'SuperAdminIncidentsPage'))->name('super-admin.incidents');
    Route::get('/vip', fn (Request $request) => $superAdminPage($request, 'SuperAdminVipPage'))->name('super-admin.vip');
    Route::get('/users/{user}', fn (Request $request, \App\Models\User $user) => Inertia::render('SuperAdminUserDetailPage', [
        'currentUser' => $request->user()?->only(['id', 'name', 'email', 'role', 'avatar_url']),
        'userId' => $user->id,
    ]))->name('super-admin.users.show');
    Route::get('/companies/{tenant}', fn (Request $request, Tenant $tenant) => Inertia::render('SuperAdminCompanyDetailPage', [
        'currentUser' => $request->user()?->only(['id', 'name', 'email', 'role', 'avatar_url']),
        'tenantId' => $tenant->id,
    ]))->name('super-admin.companies.show');
    Route::get('/support/{ticket}', fn (Request $request, \App\Models\SupportTicket $ticket) => Inertia::render('SuperAdminSupportDetailPage', [
        'currentUser' => $request->user()?->only(['id', 'name', 'email', 'role', 'avatar_url']),
        'ticketId' => $ticket->id,
    ]))->name('super-admin.support.show');
});