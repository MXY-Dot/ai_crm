<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Integrations\TenantIntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_webhook_creates_inbox_crm_and_ai_records(): void
    {
        $tenant = $this->tenantWithCompany();

        $this->withHeaders(['X-Tenant-Id' => 'demo', 'X-Telegram-Bot-Api-Secret-Token' => 'test'])->postJson('/api/telegram/webhook', [
            'update_id' => 1000,
            'message' => [
                'message_id' => 55,
                'chat' => ['id' => 777],
                'from' => ['id' => 123, 'first_name' => 'Amina', 'last_name' => 'Khan', 'username' => 'amina'],
                'text' => 'Can I book hair color tomorrow?',
            ],
        ])->assertCreated()->assertJsonPath('ok', true)->assertJsonPath('ai_run.intent', 'booking_request');

        $this->assertDatabaseHas(Channel::class, ['tenant_id' => $tenant->id, 'provider' => 'telegram', 'name' => 'Telegram Bot']);
        $this->assertDatabaseHas(Customer::class, ['tenant_id' => $tenant->id, 'name' => 'Amina Khan', 'source' => 'telegram']);
        $this->assertDatabaseHas(Conversation::class, ['tenant_id' => $tenant->id, 'external_id' => 'telegram-777']);
        $this->assertDatabaseHas(Message::class, ['tenant_id' => $tenant->id, 'external_id' => 'telegram-777-55', 'body' => 'Can I book hair color tomorrow?']);
        $this->assertDatabaseHas(AiRun::class, ['tenant_id' => $tenant->id, 'intent' => 'booking_request']);
    }

    public function test_telegram_webhook_auto_replies_when_enabled(): void
    {
        $tenant = $this->tenantWithCompany();
        config()->set('services.dify.url', 'https://dify.example');
        $settings = app(TenantIntegrationSettings::class);
        $tenant->forceFill([
            'settings' => [
                'integrations' => [
                    'dify' => ['api_key' => $settings->encrypt('dify-token')],
                    'telegram' => [
                        'bot_token' => $settings->encrypt('telegram-token'),
                        'auto_reply_enabled' => true,
                    ],
                ],
            ],
        ])->save();

        Http::fake([
            'https://dify.example/*' => Http::response(['answer' => json_encode(['confidence' => 90, 'intent' => 'booking_request', 'summary' => 'Booking request.', 'reply_text' => 'Yes, I can help book tomorrow.', 'next_action' => 'reply', 'handoff_required' => false])], 200),
            'https://api.telegram.org/bottelegram-token/sendChatAction' => Http::response(['ok' => true], 200),
            'https://api.telegram.org/bottelegram-token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 900]], 200),
        ]);

        $this->withHeaders(['X-Tenant-Id' => 'demo'])->postJson('/api/telegram/webhook', [
            'message' => [
                'message_id' => 56,
                'chat' => ['id' => 777],
                'from' => ['first_name' => 'Amina'],
                'text' => 'Can I book tomorrow?',
            ],
        ])->assertCreated()->assertJsonPath('ok', true);

        $this->assertDatabaseHas(Message::class, [
            'tenant_id' => $tenant->id,
            'conversation_id' => Conversation::withoutGlobalScopes()->where('external_id', 'telegram-777')->value('id'),
            'sender_type' => 'ai',
            'external_id' => '900',
        ]);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bottelegram-token/sendMessage'
            && $request['chat_id'] === '777'
            && is_string($request['text'])
            && $request['text'] !== '');
    }

    public function test_telegram_webhook_is_idempotent(): void
    {
        $this->tenantWithCompany();
        $payload = [
            'message' => [
                'message_id' => 55,
                'chat' => ['id' => 777],
                'from' => ['first_name' => 'Amina'],
                'text' => 'Can I book hair color tomorrow?',
            ],
        ];

        $this->withHeaders(['X-Tenant-Id' => 'demo', 'X-Telegram-Bot-Api-Secret-Token' => 'test'])->postJson('/api/telegram/webhook', $payload)->assertCreated();
        $this->withHeaders(['X-Tenant-Id' => 'demo', 'X-Telegram-Bot-Api-Secret-Token' => 'test'])->postJson('/api/telegram/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, Message::withoutGlobalScopes()->where('external_id', 'telegram-777-55')->count());
    }

    public function test_telegram_webhook_can_require_secret(): void
    {
        $tenant = $this->tenantWithCompany();
        $tenant->forceFill([
            'settings' => [
                'integrations' => [
                    'telegram' => ['webhook_secret' => app(TenantIntegrationSettings::class)->encrypt('secret')],
                ],
            ],
        ])->save();

        $payload = ['message' => ['message_id' => 1, 'chat' => ['id' => 1], 'text' => 'Hello']];

        $this->withHeaders(['X-Tenant-Id' => 'demo', 'X-Telegram-Bot-Api-Secret-Token' => 'test'])->postJson('/api/telegram/webhook', $payload)->assertUnauthorized();
        $this->withHeaders(['X-Tenant-Id' => 'demo', 'X-Telegram-Bot-Api-Secret-Token' => 'secret'])
            ->postJson('/api/telegram/webhook', $payload)
            ->assertCreated();
    }

    private function tenantWithCompany(): Tenant
    {
        $tenant = Tenant::query()->create(['name' => 'Demo', 'slug' => 'demo']);
        Company::query()->create(['tenant_id' => $tenant->id, 'name' => 'Studio']);

        return $tenant;
    }
}
