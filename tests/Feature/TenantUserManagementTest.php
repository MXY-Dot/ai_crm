<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_tenant_user(): void
    {
        [$tenant, $owner] = $this->tenantUser('owner');

        $this->actingAs($owner)
            ->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/tenant-users', [
                'name' => 'Support Operator',
                'email' => 'support@gravity.test',
                'phone' => '+92 300 111 2222',
                'role' => 'operator',
                'password' => 'password123',
            ])
            ->assertCreated()
            ->assertJsonPath('email', 'support@gravity.test')
            ->assertJsonPath('role', 'operator')
            ->assertJsonMissing(['password' => 'password123']);

        $user = User::query()->where('email', 'support@gravity.test')->firstOrFail();

        $this->assertSame($tenant->id, $user->tenant_id);
        $this->assertDatabaseHas(AuditLog::class, [
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'action' => 'tenant_user.created',
            'entity_type' => User::class,
            'entity_id' => $user->id,
        ]);
    }

    public function test_owner_can_update_tenant_user_role_and_status(): void
    {
        [$tenant, $owner] = $this->tenantUser('owner');
        $member = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'operator', 'status' => 'invited']);

        $this->actingAs($owner)
            ->withHeader('X-Tenant-Id', 'demo')
            ->patchJson('/api/tenant-users/'.$member->id, [
                'role' => 'manager',
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('role', 'manager')
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas(AuditLog::class, [
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'action' => 'tenant_user.updated',
            'entity_type' => User::class,
            'entity_id' => $member->id,
        ]);
    }

    public function test_super_admin_can_manage_users_in_any_tenant(): void
    {
        $targetTenant = Tenant::query()->create(['name' => 'Target', 'slug' => 'target']);
        $admin = User::factory()->create(['tenant_id' => null, 'role' => User::ROLE_SUPER_ADMIN, 'status' => 'active']);

        $this->actingAs($admin)
            ->withHeader('X-Tenant-Id', 'target')
            ->postJson('/api/tenant-users', [
                'name' => 'Target Manager',
                'email' => 'manager@target.test',
                'role' => 'manager',
            ])
            ->assertCreated()
            ->assertJsonPath('email', 'manager@target.test');

        $this->assertDatabaseHas(User::class, [
            'tenant_id' => $targetTenant->id,
            'email' => 'manager@target.test',
            'role' => 'manager',
        ]);
    }
    public function test_operator_cannot_manage_tenant_users(): void
    {
        [, $operator] = $this->tenantUser('operator');

        $this->actingAs($operator)
            ->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/tenant-users', [
                'name' => 'Blocked User',
                'email' => 'blocked@gravity.test',
                'role' => 'operator',
            ])
            ->assertForbidden();
    }

    public function test_tenant_user_list_is_scoped_to_active_tenant(): void
    {
        [$tenant, $owner] = $this->tenantUser('owner');
        $otherTenant = Tenant::query()->create(['name' => 'Other', 'slug' => 'other']);
        User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'visible@gravity.test', 'role' => 'operator']);
        User::factory()->create(['tenant_id' => $otherTenant->id, 'email' => 'hidden@gravity.test', 'role' => 'operator']);

        $this->actingAs($owner)
            ->withHeader('X-Tenant-Id', 'demo')
            ->getJson('/api/tenant-users')
            ->assertOk()
            ->assertJsonFragment(['email' => 'visible@gravity.test'])
            ->assertJsonMissing(['email' => 'hidden@gravity.test']);
    }

    private function tenantUser(string $role): array
    {
        $tenant = Tenant::query()->create(['name' => 'Demo', 'slug' => 'demo']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role, 'status' => 'active']);

        return [$tenant, $user];
    }
}