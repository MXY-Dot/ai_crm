<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_and_update_tenants(): void
    {
        $admin = User::factory()->create(['tenant_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin)->postJson('/api/tenants', [
            'name' => 'Admin Tenant',
            'slug' => 'admin-tenant',
            'status' => 'trial',
        ])->assertCreated();

        $tenantId = $response->json('id');

        $this->actingAs($admin)
            ->patchJson('/api/tenants/'.$tenantId, ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('status', 'active');
    }

    public function test_owner_cannot_create_tenants(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Demo', 'slug' => 'demo']);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => User::ROLE_OWNER]);

        $this->actingAs($owner)
            ->postJson('/api/tenants', ['name' => 'Blocked', 'slug' => 'blocked'])
            ->assertForbidden();
    }
}