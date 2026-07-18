<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_only_sees_companies_from_active_tenant(): void
    {
        [$tenant, $user] = $this->tenantUser('owner');
        $otherTenant = Tenant::query()->create(['name' => 'Other', 'slug' => 'other']);

        Company::query()->create(['tenant_id' => $tenant->id, 'name' => 'Visible Company']);
        Company::query()->create(['tenant_id' => $otherTenant->id, 'name' => 'Hidden Company']);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/companies')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Visible Company')
            ->assertJsonMissing(['name' => 'Hidden Company']);
    }

    public function test_tenant_header_is_required_for_tenant_resources(): void
    {
        [, $user] = $this->tenantUser('owner');

        $this->actingAs($user)
            ->getJson('/api/companies')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Tenant context is required.');
    }

    public function test_operator_cannot_create_company_records(): void
    {
        [$tenant, $user] = $this->tenantUser('operator');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->postJson('/api/companies', ['name' => 'Blocked Company'])
            ->assertForbidden();
    }

    private function tenantUser(string $role): array
    {
        $tenant = Tenant::query()->create(['name' => fake()->company(), 'slug' => fake()->unique()->slug()]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);

        return [$tenant, $user];
    }
}