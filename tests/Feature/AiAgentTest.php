<?php

namespace Tests\Feature;

use App\Models\AiAgent;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_ai_agent_profile(): void
    {
        [$tenant, $agent, $user] = $this->setupAgent('owner');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->slug)
            ->patchJson('/api/ai-agents/'.$agent->id, [
                'name' => 'Front desk assistant',
                'status' => 'active',
                'handoff_threshold' => 82,
                'instructions' => 'Answer briefly, ask for date, service and phone.',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Front desk assistant')
            ->assertJsonPath('handoff_threshold', 82);

        $this->assertDatabaseHas(AiAgent::class, [
            'tenant_id' => $tenant->id,
            'name' => 'Front desk assistant',
            'handoff_threshold' => 82,
        ]);
    }

    public function test_operator_cannot_update_ai_agent_profile(): void
    {
        [$tenant, $agent, $user] = $this->setupAgent('operator');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->slug)
            ->patchJson('/api/ai-agents/'.$agent->id, ['handoff_threshold' => 50])
            ->assertForbidden();
    }

    private function setupAgent(string $role): array
    {
        $tenant = Tenant::query()->create(['name' => 'Demo', 'slug' => 'demo']);
        $company = Company::query()->create(['tenant_id' => $tenant->id, 'name' => 'Studio']);
        $agent = AiAgent::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Default Dify Assistant',
            'provider' => 'dify',
            'status' => 'active',
            'handoff_threshold' => 70,
            'instructions' => 'Old instructions',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);

        return [$tenant, $agent, $user];
    }
}
