<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Integrations\TenantIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatwootSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_sync_customer_conversations_from_chatwoot(): void
    {
        [$tenant, $user] = $this->setupTenant('owner');

        Http::fake([
            'chatwoot.example/api/v1/accounts/2/conversations' => Http::response([
                'data' => [
                    'payload' => [
                        [
                            'id' => 42,
                            'display_id' => 42,
                            'status' => 'open',
                            'priority' => 'high',
                            'channel' => 'Channel::WebWidget',
                            'inbox' => ['id' => 7, 'name' => 'Website Widget'],
                            'meta' => ['sender' => ['name' => 'Amina Khan', 'email' => 'amina@example.com']],
                            'messages' => [
                                ['id' => 100, 'content' => 'Outgoing agent note', 'message_type' => 1, 'created_at' => 10, 'sender' => ['type' => 'user', 'name' => 'Agent']],
                                ['id' => 101, 'content' => 'Can I book hair color tomorrow?', 'message_type' => 0, 'created_at' => 20, 'sender' => ['type' => 'contact', 'name' => 'Amina Khan', 'email' => 'amina@example.com']],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/chatwoot/sync')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('duplicates', 0)
            ->assertJsonPath('skipped', 0);

        Http::assertSent(fn ($request) => $request->hasHeader('api_access_token', 'chatwoot-token')
            && $request->url() === 'https://chatwoot.example/api/v1/accounts/2/conversations');

        $this->assertDatabaseHas(Channel::class, ['tenant_id' => $tenant->id, 'provider' => 'website', 'name' => 'Website Widget']);
        $this->assertDatabaseHas(Customer::class, ['tenant_id' => $tenant->id, 'name' => 'Amina Khan', 'email' => 'amina@example.com']);
        $this->assertDatabaseHas(Conversation::class, ['tenant_id' => $tenant->id, 'external_id' => '42', 'priority' => 'high']);
        $this->assertDatabaseHas(Message::class, ['tenant_id' => $tenant->id, 'external_id' => '101', 'body' => 'Can I book hair color tomorrow?']);
    }

    public function test_operator_cannot_sync_chatwoot(): void
    {
        [, $user] = $this->setupTenant('operator');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/chatwoot/sync')
            ->assertForbidden();
    }

    private function setupTenant(string $role): array
    {
        $settings = app(TenantIntegrationSettings::class);
        $tenant = Tenant::query()->create([
            'name' => 'Demo',
            'slug' => 'demo',
            'settings' => [
                'integrations' => [
                    'chatwoot' => [
                        'url' => 'https://chatwoot.example',
                        'account_id' => 2,
                        'api_token' => $settings->encrypt('chatwoot-token'),
                    ],
                ],
            ],
        ]);
        Company::query()->create(['tenant_id' => $tenant->id, 'name' => 'Studio']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);

        return [$tenant, $user];
    }
}