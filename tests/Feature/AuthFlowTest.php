<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_is_public_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('HomePage'));
    }

    public function test_guests_see_dedicated_auth_pages(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('LoginPage'));

        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('RegisterPage'));
    }

    public function test_guest_is_redirected_to_login_from_dashboard(): void
    {
        $this->get('/app')->assertRedirect('/login');
    }

    public function test_owner_can_login_and_access_dashboard(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Demo', 'slug' => 'demo']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'owner@gravity.test',
            'password' => 'password',
            'role' => 'owner',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/app');

        $this->assertAuthenticatedAs($user);
        $this->get('/app')->assertOk();
    }

    public function test_user_can_register_workspace_with_plan(): void
    {
        $this->post('/register', [
            'name' => 'Amina Owner',
            'workspace' => 'Amina Studio',
            'email' => 'amina@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'plan' => 'growth',
        ])->assertRedirect('/app');

        $this->assertAuthenticated();
        $this->assertDatabaseHas(User::class, ['email' => 'amina@example.com', 'role' => 'owner']);
        $this->assertDatabaseHas(Tenant::class, ['name' => 'Amina Studio', 'slug' => 'amina-studio', 'status' => 'trial']);
        $this->assertDatabaseHas(Company::class, ['name' => 'Amina Studio']);
        $this->assertSame('growth', Tenant::query()->where('slug', 'amina-studio')->firstOrFail()->settings['billing']['plan']);
    }

    public function test_google_login_redirect_is_safe_when_not_configured(): void
    {
        config(['services.google.client_id' => null, 'services.google.redirect' => null]);

        $this->get('/auth/google')->assertRedirect('/login');
    }

    public static function dashboardPages(): array
    {
        return [
            ['/app', 'OverviewPage'],
            ['/inbox', 'InboxPage'],
            ['/leads', 'LeadsPage'],
            ['/customers', 'CustomerProfilePage'],
            ['/crm', 'CrmPage'],
            ['/ai', 'AiPage'],
            ['/knowledge', 'KnowledgePage'],
            ['/analytics', 'AnalyticsPage'],
            ['/integrations', 'IntegrationsPage'],
            ['/settings', 'SettingsPage'],
        ];
    }

    #[DataProvider('dashboardPages')]
    public function test_authenticated_user_can_open_each_concrete_inertia_page(
        string $uri,
        string $component,
    ): void {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get($uri)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component($component)
                ->has('bootstrap'));
    }

    public function test_unknown_dashboard_page_is_not_captured_by_spa_route(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Demo', 'slug' => 'demo']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

        $this->actingAs($user)->get('/unknown-page')->assertNotFound();
    }

    public function test_api_me_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }
}