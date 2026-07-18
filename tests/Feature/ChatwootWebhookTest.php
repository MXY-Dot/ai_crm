<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Support\Integrations\TenantIntegrationSettings;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatwootWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_chatwoot_webhook_creates_inbox_crm_and_ai_records(): void
    {
        $tenant = $this->tenantWithCompany();

        $response = $this->withHeader('X-Tenant-Id', 'demo')->postJson('/api/chatwoot/webhook', [
            'event' => 'message_created',
            'provider' => 'telegram',
            'inbox' => ['id' => 'inbox-1', 'name' => 'Telegram Bot'],
            'conversation' => ['id' => 'cw-1001', 'subject' => 'Hair color appointment', 'status' => 'open', 'priority' => 'high'],
            'sender' => ['name' => 'Amina Khan', 'phone_number' => '+92 300 100 2001', 'type' => 'customer'],
            'message' => ['id' => 'msg-1', 'content' => 'Can I book hair color after 6 PM?'],
            'ai' => ['handoff' => true],
        ]);

        $response->assertCreated()->assertJsonPath('ok', true)->assertJsonPath('ai_run.intent', 'booking_request');

        $this->assertDatabaseHas(Channel::class, ['tenant_id' => $tenant->id, 'provider' => 'telegram', 'name' => 'Telegram Bot']);
        $this->assertDatabaseHas(Customer::class, ['tenant_id' => $tenant->id, 'name' => 'Amina Khan', 'phone' => '+92 300 100 2001']);
        $this->assertDatabaseHas(Lead::class, ['tenant_id' => $tenant->id, 'title' => 'Hair color appointment', 'score' => 88]);
        $this->assertDatabaseHas(Conversation::class, ['tenant_id' => $tenant->id, 'external_id' => 'cw-1001', 'priority' => 'high']);
        $this->assertDatabaseHas(Message::class, ['tenant_id' => $tenant->id, 'external_id' => 'msg-1', 'body' => 'Can I book hair color after 6 PM?']);
        $this->assertDatabaseHas(AiRun::class, ['tenant_id' => $tenant->id, 'intent' => 'booking_request', 'next_action' => 'suggest_slots']);
        $this->assertDatabaseHas(CrmTask::class, ['tenant_id' => $tenant->id, 'title' => 'Review Chatwoot handoff: Hair color appointment']);
    }

    public function test_chatwoot_webhook_ai_workflow_qualifies_clear_booking(): void
    {
        $tenant = $this->tenantWithCompany();

        $this->withHeader('X-Tenant-Id', 'demo')->postJson('/api/chatwoot/webhook', [
            'provider' => 'website',
            'conversation' => ['id' => 'cw-booking', 'subject' => 'Booking request'],
            'sender' => ['name' => 'Noah Reed'],
            'message' => ['id' => 'msg-booking', 'content' => 'Can I book an appointment tomorrow after 6 PM?'],
        ])->assertCreated()->assertJsonPath('ai_run.intent', 'booking_request')->assertJsonPath('ai_run.next_action', 'suggest_slots');

        $this->assertDatabaseHas(AiRun::class, ['tenant_id' => $tenant->id, 'intent' => 'booking_request', 'next_action' => 'suggest_slots']);
        $this->assertDatabaseHas(Lead::class, ['tenant_id' => $tenant->id, 'title' => 'Booking request', 'status' => 'qualified']);
    }

    public function test_chatwoot_webhook_ai_workflow_hands_off_payment_policy_questions(): void
    {
        $tenant = $this->tenantWithCompany();

        $this->withHeader('X-Tenant-Id', 'demo')->postJson('/api/chatwoot/webhook', [
            'provider' => 'whatsapp',
            'conversation' => ['id' => 'cw-payment', 'subject' => 'Deposit question'],
            'sender' => ['name' => 'Sara Malik'],
            'message' => ['id' => 'msg-payment', 'content' => 'I paid deposit. Can I get a refund or payment invoice?'],
        ])->assertCreated()->assertJsonPath('ai_run.intent', 'payment_policy')->assertJsonPath('ai_run.next_action', 'handoff_operator');

        $this->assertDatabaseHas(AiRun::class, ['tenant_id' => $tenant->id, 'intent' => 'payment_policy', 'next_action' => 'handoff_operator']);
        $this->assertDatabaseHas(Conversation::class, ['tenant_id' => $tenant->id, 'external_id' => 'cw-payment', 'status' => 'pending_operator', 'priority' => 'high']);
        $this->assertDatabaseHas(CrmTask::class, ['tenant_id' => $tenant->id, 'title' => 'AI handoff: Deposit question', 'priority' => 'high']);
    }

    public function test_chatwoot_webhook_uses_dify_when_configured(): void
    {
        config(['services.dify.url' => 'https://dify.example/v1', 'services.dify.api_key' => 'secret']);

        Http::fake([
            'dify.example/*' => Http::response([
                'answer' => json_encode([
                    'confidence' => 91,
                    'intent' => 'vip_booking',
                    'summary' => 'Dify says this is a VIP booking request.',
                    'reply' => 'Absolutely, I can help with a premium slot.',
                    'next_action' => 'book_vip_slot',
                    'handoff_required' => false,
                ]),
            ]),
        ]);

        $tenant = $this->tenantWithCompany();

        $this->withHeader('X-Tenant-Id', 'demo')->postJson('/api/chatwoot/webhook', [
            'provider' => 'website',
            'conversation' => ['id' => 'cw-dify', 'subject' => 'VIP booking'],
            'sender' => ['name' => 'VIP Client'],
            'message' => ['id' => 'msg-dify', 'content' => 'Please book a premium slot for tomorrow.'],
        ])->assertCreated()->assertJsonPath('ai_run.intent', 'vip_booking')->assertJsonPath('ai_run.next_action', 'book_vip_slot');

        $this->assertDatabaseHas(AiRun::class, ['tenant_id' => $tenant->id, 'intent' => 'vip_booking', 'confidence' => 91]);
        $this->assertDatabaseHas(Lead::class, ['tenant_id' => $tenant->id, 'title' => 'VIP booking', 'score' => 91, 'status' => 'qualified']);
    }

    public function test_dify_request_includes_recent_messages_and_knowledge_context(): void
    {
        config(['services.dify.url' => 'https://dify.example/v1', 'services.dify.api_key' => 'secret']);

        $tenant = $this->tenantWithCompany();
        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $company->forceFill([
            'phone' => '+1 555 0100',
            'address' => '12 Demo Street',
            'working_hours' => ['summary' => 'Mon-Fri 10:00-19:00'],
            'brand_settings' => [
                'services' => 'Hair color from 80 USD.',
                'booking_rules' => 'Ask for date and phone before booking.',
            ],
        ])->save();
        $document = KnowledgeDocument::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'title' => 'Service policy',
            'source_type' => 'manual',
            'status' => 'indexed',
            'indexed_at' => now(),
        ]);
        KnowledgeChunk::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'knowledge_document_id' => $document->id,
            'position' => 1,
            'content' => 'Premium bookings require a 20 percent deposit.',
            'token_count' => 8,
        ]);

        Http::fake([
            'dify.example/*' => Http::response([
                'answer' => json_encode([
                    'confidence' => 82,
                    'intent' => 'pricing_request',
                    'summary' => 'Customer asks about premium booking deposit.',
                    'reply_text' => '<think>This internal reasoning must stay hidden.</think>Premium bookings require a 20 percent deposit. I can help reserve a slot.',
                    'next_action' => 'send_offer',
                    'handoff_required' => false,
                ]),
            ]),
        ]);

        $this->withHeader('X-Tenant-Id', 'demo')->postJson('/api/chatwoot/webhook', [
            'provider' => 'website',
            'conversation' => ['id' => 'cw-kb-dify', 'subject' => 'Premium deposit'],
            'sender' => ['name' => 'Knowledge Client'],
            'message' => ['id' => 'msg-kb-dify', 'content' => 'How much deposit for premium booking?'],
        ])->assertCreated()->assertJsonPath('ai_run.intent', 'pricing_request');

        Http::assertSent(fn ($request) => str_contains($request['inputs']['knowledge_context'], '20 percent deposit')
            && str_contains($request['inputs']['business_profile'], 'Hair color from 80 USD')
            && str_contains($request['inputs']['business_profile'], 'Mon-Fri 10:00-19:00')
            && str_contains($request['inputs']['recent_messages'], 'customer: How much deposit'));

        $this->assertDatabaseHas(Message::class, [
            'tenant_id' => $tenant->id,
            'sender_type' => 'ai',
            'body' => 'Premium bookings require a 20 percent deposit. I can help reserve a slot.',
        ]);
    }

    public function test_dify_markdown_json_answer_is_parsed_into_reply_text(): void
    {
        config(['services.dify.url' => 'https://dify.example/v1', 'services.dify.api_key' => 'secret']);

        Http::fake([
            'dify.example/*' => Http::response([
                'answer' => "```json\n{\"confidence\":0.95,\"intent\":\"pricing_request\",\"summary\":\"Customer asks price.\",\"reply_text\":\"Bridal makeup starts at 220 USD.\",\"next_action\":\"send_price\",\"handoff_required\":false}\n```",
            ]),
        ]);

        $tenant = $this->tenantWithCompany();

        $this->withHeader('X-Tenant-Id', 'demo')->postJson('/api/chatwoot/webhook', [
            'event' => 'message_created',
            'id' => 601,
            'content' => 'How much is bridal makeup?',
            'conversation_id' => 88,
            'message_type' => 0,
            'sender' => ['type' => 'contact', 'name' => 'Markdown JSON Visitor'],
        ])->assertCreated()->assertJsonPath('ai_run.intent', 'pricing_request');

        $this->assertDatabaseHas(AiRun::class, ['tenant_id' => $tenant->id, 'intent' => 'pricing_request', 'confidence' => 95]);
        $this->assertDatabaseHas(Message::class, ['tenant_id' => $tenant->id, 'sender_type' => 'ai', 'body' => 'Bridal makeup starts at 220 USD.']);
    }
    public function test_chatwoot_webhook_falls_back_to_local_ai_when_dify_fails(): void
    {
        config(['services.dify.url' => 'https://dify.example/v1', 'services.dify.api_key' => 'secret']);
        Http::fake(['dify.example/*' => Http::response([], 500)]);

        $tenant = $this->tenantWithCompany();

        $this->withHeader('X-Tenant-Id', 'demo')->postJson('/api/chatwoot/webhook', [
            'provider' => 'website',
            'conversation' => ['id' => 'cw-fallback', 'subject' => 'Fallback booking'],
            'sender' => ['name' => 'Fallback Client'],
            'message' => ['id' => 'msg-fallback', 'content' => 'Can I book an appointment tomorrow?'],
        ])->assertCreated()->assertJsonPath('ai_run.intent', 'booking_request');

        $this->assertDatabaseHas(AiRun::class, ['tenant_id' => $tenant->id, 'intent' => 'booking_request', 'next_action' => 'suggest_slots']);
    }

    public function test_chatwoot_webhook_duplicate_message_is_idempotent(): void
    {
        $tenant = $this->tenantWithCompany();
        $payload = [
            'provider' => 'website',
            'conversation' => ['id' => 'cw-duplicate', 'subject' => 'Duplicate booking'],
            'sender' => ['name' => 'Repeat Client'],
            'message' => ['id' => 'msg-duplicate', 'content' => 'Can I book an appointment tomorrow?'],
        ];

        $this->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/chatwoot/webhook', $payload)
            ->assertCreated()
            ->assertJsonPath('duplicate', false);

        $response = $this->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/chatwoot/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $conversation = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('external_id', 'cw-duplicate')
            ->firstOrFail();

        $response->assertJsonPath('ai_run.conversation_id', $conversation->id);

        $this->assertSame(1, Message::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('external_id', 'msg-duplicate')->count());
        $this->assertSame(1, AiRun::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('conversation_id', $conversation->id)->count());
    }
    public function test_chatwoot_webhook_accepts_native_chatwoot_message_payload(): void
    {
        $tenant = $this->tenantWithCompany();

        $this->withHeader('X-Tenant-Id', 'demo')->postJson('/api/chatwoot/webhook', [
            'event' => 'message_created',
            'id' => 501,
            'content' => 'I need a consultation tomorrow.',
            'conversation_id' => 77,
            'inbox_id' => 5,
            'message_type' => 0,
            'sender' => ['type' => 'contact', 'name' => 'Native Visitor', 'email' => 'native@example.com'],
        ])->assertCreated()->assertJsonPath('ok', true);

        $this->assertDatabaseHas(Conversation::class, ['tenant_id' => $tenant->id, 'external_id' => '77']);
        $this->assertDatabaseHas(Message::class, ['tenant_id' => $tenant->id, 'external_id' => '501', 'body' => 'I need a consultation tomorrow.']);
        $this->assertDatabaseHas(Customer::class, ['tenant_id' => $tenant->id, 'name' => 'Native Visitor', 'email' => 'native@example.com']);
    }

    public function test_chatwoot_webhook_stores_outgoing_operator_messages_without_ai_loop(): void
    {
        $tenant = $this->tenantWithCompany();

        $this->withHeader('X-Tenant-Id', 'demo')->postJson('/api/chatwoot/webhook', [
            'event' => 'message_created',
            'id' => 502,
            'content' => 'CRM reply from operator.',
            'conversation_id' => 77,
            'message_type' => 1,
            'sender' => ['type' => 'user', 'name' => 'Agent'],
        ])->assertCreated()->assertJsonPath('ok', true);

        $this->assertDatabaseHas(Message::class, [
            'tenant_id' => $tenant->id,
            'external_id' => '502',
            'sender_type' => 'operator',
            'body' => 'CRM reply from operator.',
        ]);
        $this->assertSame(0, AiRun::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_chatwoot_webhook_auto_replies_when_enabled(): void
    {
        config(['services.chatwoot.url' => 'https://chatwoot.example', 'services.dify.url' => 'https://dify.example/v1', 'services.dify.api_key' => 'secret']);

        $settings = app(TenantIntegrationSettings::class);
        $tenant = $this->tenantWithCompany();
        $tenant->forceFill([
            'settings' => [
                'integrations' => [
                    'chatwoot' => [
                        'account_id' => 2,
                        'api_token' => $settings->encrypt('chatwoot-token'),
                        'auto_reply_enabled' => true,
                    ],
                ],
            ],
        ])->save();

        Http::fake([
            'dify.example/*' => Http::response(['answer' => json_encode(['confidence' => 91, 'intent' => 'booking_request', 'summary' => 'Booking request.', 'reply_text' => 'Yes, I can help book tomorrow.', 'next_action' => 'reply', 'handoff_required' => false])], 200),
            'chatwoot.example/api/v1/accounts/2/conversations/cw-auto/toggle_typing_status' => Http::response(['ok' => true], 200),
            'chatwoot.example/api/v1/accounts/2/conversations/cw-auto/messages' => Http::response(['id' => 777], 200),
        ]);

        $this->withHeader('X-Tenant-Id', 'demo')->postJson('/api/chatwoot/webhook', [
            'event' => 'message_created',
            'id' => 701,
            'content' => 'Can I book tomorrow?',
            'conversation_id' => 'cw-auto',
            'inbox_id' => 1,
            'message_type' => 0,
            'sender' => ['type' => 'contact', 'name' => 'Auto Reply Visitor'],
        ])->assertCreated()->assertJsonPath('ok', true);

        Http::assertSent(fn ($request) => $request->url() === 'https://chatwoot.example/api/v1/accounts/2/conversations/cw-auto/messages'
            && $request->hasHeader('api_access_token', 'chatwoot-token')
            && $request['message_type'] === 'outgoing'
            && $request['content'] === 'Yes, I can help book tomorrow.');

        $this->assertDatabaseHas(Message::class, [
            'tenant_id' => $tenant->id,
            'sender_type' => 'ai',
            'external_id' => '777',
        ]);
    }

    public function test_chatwoot_webhook_ignores_native_activity_messages(): void
    {
        $this->tenantWithCompany();

        $this->withHeader('X-Tenant-Id', 'demo')->postJson('/api/chatwoot/webhook', [
            'event' => 'message_created',
            'id' => 503,
            'content' => 'Default policy assigned Mahmud as responsible.',
            'conversation_id' => 77,
            'message_type' => 2,
            'sender' => ['type' => 'system', 'name' => 'Chatwoot'],
        ])->assertOk()->assertJsonPath('ignored', true)->assertJsonPath('reason', 'system_message');

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }
    public function test_chatwoot_webhook_requires_tenant_context(): void
    {
        $this->postJson('/api/chatwoot/webhook', ['message' => ['content' => 'Hello']])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Tenant context is required.');
    }

    public function test_chatwoot_webhook_can_require_secret(): void
    {
        config(['services.chatwoot.webhook_secret' => 'secret']);
        $this->tenantWithCompany();

        $this->withHeader('X-Tenant-Id', 'demo')->postJson('/api/chatwoot/webhook', ['message' => ['content' => 'Hello']])->assertUnauthorized();
        $this->withHeaders(['X-Tenant-Id' => 'demo', 'X-Webhook-Secret' => 'secret'])->postJson('/api/chatwoot/webhook', ['message' => ['content' => 'Hello']])->assertCreated();
    }
    public function test_chatwoot_webhook_accepts_chatwoot_hmac_signature(): void
    {
        config(['services.chatwoot.webhook_secret' => 'secret']);
        $this->tenantWithCompany();
        $payload = ['message' => ['content' => 'Hello']];
        $body = json_encode($payload);
        $timestamp = '1234567890';
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'secret');

        $this->call('POST', '/api/chatwoot/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_TENANT_ID' => 'demo',
            'HTTP_X_CHATWOOT_TIMESTAMP' => $timestamp,
            'HTTP_X_CHATWOOT_SIGNATURE' => $signature,
        ], $body)->assertCreated();
    }

    public function test_chatwoot_webhook_is_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->withHeader('X-Tenant-Id', 'missing-'.$i)->postJson('/api/chatwoot/webhook', [
                'message' => ['content' => 'Hello'],
            ])->assertUnprocessable();
        }

        $this->withHeader('X-Tenant-Id', 'missing-final')->postJson('/api/chatwoot/webhook', [
            'message' => ['content' => 'Hello'],
        ])->assertTooManyRequests();
    }
    private function tenantWithCompany(): Tenant
    {
        $tenant = Tenant::query()->create(['name' => 'Demo', 'slug' => 'demo']);
        Company::query()->create(['tenant_id' => $tenant->id, 'name' => 'Studio']);

        return $tenant;
    }
}