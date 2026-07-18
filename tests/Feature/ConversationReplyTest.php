<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Integrations\TenantIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConversationReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_send_reply_to_chatwoot_conversation(): void
    {
        [$tenant, $conversation, $user] = $this->setupConversation('owner');
        config(['services.chatwoot.url' => 'https://chatwoot.example']);
        $settings = app(TenantIntegrationSettings::class);
        $tenant->forceFill([
            'settings' => [
                'integrations' => [
                    'chatwoot' => [
                        'account_id' => 2,
                        'api_token' => $settings->encrypt('chatwoot-token'),
                    ],
                ],
            ],
        ])->save();

        Http::fake([
            'chatwoot.example/api/v1/accounts/2/conversations/cw-42/messages' => Http::response([
                'id' => 9001,
                'content' => 'Hello from CRM',
            ], 200),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/conversations/'.$conversation->id.'/reply', ['body' => 'Hello from CRM'])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message.sender_type', 'operator')
            ->assertJsonPath('message.external_id', '9001');

        Http::assertSent(fn ($request) => $request->hasHeader('api_access_token', 'chatwoot-token')
            && $request->url() === 'https://chatwoot.example/api/v1/accounts/2/conversations/cw-42/messages'
            && $request['content'] === 'Hello from CRM'
            && $request['message_type'] === 'outgoing');

        $this->assertDatabaseHas('messages', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'operator',
            'sender_name' => $user->name,
            'body' => 'Hello from CRM',
            'external_id' => '9001',
        ]);
    }

    public function test_owner_can_send_reply_to_telegram_conversation(): void
    {
        [$tenant, $conversation, $user] = $this->setupConversation('owner');
        $channel = Channel::withoutGlobalScopes()->whereKey($conversation->channel_id)->firstOrFail();
        $channel->forceFill(['provider' => 'telegram', 'name' => 'Telegram Bot'])->save();
        $conversation->forceFill(['external_id' => 'telegram-777'])->save();
        $tenant->forceFill([
            'settings' => [
                'integrations' => [
                    'telegram' => ['bot_token' => app(TenantIntegrationSettings::class)->encrypt('telegram-token')],
                ],
            ],
        ])->save();

        Http::fake([
            'api.telegram.org/bottelegram-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 901],
            ], 200),
        ]);

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/conversations/'.$conversation->id.'/reply', ['body' => 'Hello Telegram'])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message.sender_type', 'operator')
            ->assertJsonPath('message.external_id', 'telegram-777-901');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '777'
            && $request['text'] === 'Hello Telegram');

        $this->assertDatabaseHas('messages', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'operator',
            'body' => 'Hello Telegram',
            'external_id' => 'telegram-777-901',
        ]);
    }
    public function test_operator_cannot_send_reply_with_current_permissions(): void
    {
        [, $conversation, $user] = $this->setupConversation('operator');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/conversations/'.$conversation->id.'/reply', ['body' => 'Hello'])
            ->assertForbidden();
    }

    private function setupConversation(string $role): array
    {
        $tenant = Tenant::query()->create(['name' => 'Demo', 'slug' => 'demo']);
        $company = Company::query()->create(['tenant_id' => $tenant->id, 'name' => 'Studio']);
        $customer = Customer::query()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id, 'name' => 'Customer']);
        $channel = Channel::query()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id, 'provider' => 'website', 'name' => 'Website']);
        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'channel_id' => $channel->id,
            'customer_id' => $customer->id,
            'external_id' => 'cw-42',
            'subject' => 'Test conversation',
            'status' => 'open',
            'priority' => 'normal',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);

        return [$tenant, $conversation, $user];
    }
}
