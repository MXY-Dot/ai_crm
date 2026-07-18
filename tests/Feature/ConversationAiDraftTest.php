<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConversationAiDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_generate_dify_draft_for_chatwoot_conversation(): void
    {
        config(['services.dify.url' => 'https://dify.example/v1', 'services.dify.api_key' => 'secret']);

        Http::fake([
            'dify.example/*' => Http::response([
                'answer' => json_encode([
                    'confidence' => 92,
                    'intent' => 'booking_request',
                    'summary' => 'Customer wants a color appointment tomorrow.',
                    'reply' => 'Sure, I can help with a color appointment. What time works best tomorrow?',
                    'next_action' => 'suggest_slots',
                    'handoff_required' => false,
                ]),
            ]),
        ]);

        [$tenant, $conversation, $user] = $this->conversationWithCustomerMessage();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/conversations/'.$conversation->id.'/ai-draft')
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('draft_message.sender_type', 'ai')
            ->assertJsonPath('draft_message.body', 'Sure, I can help with a color appointment. What time works best tomorrow?')
            ->assertJsonPath('ai_run.intent', 'booking_request');

        $this->assertDatabaseHas(Message::class, [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'ai',
            'sender_name' => 'Dify AI',
        ]);
        $this->assertDatabaseHas(AiRun::class, ['tenant_id' => $tenant->id, 'intent' => 'booking_request', 'confidence' => 92]);
    }

    private function conversationWithCustomerMessage(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Demo', 'slug' => 'demo']);
        $company = Company::query()->create(['tenant_id' => $tenant->id, 'name' => 'Studio']);
        $customer = Customer::query()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id, 'name' => 'Customer']);
        $lead = Lead::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'title' => 'Color appointment',
            'status' => 'new',
            'source' => 'chatwoot',
            'score' => 50,
        ]);
        $channel = Channel::query()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id, 'provider' => 'website', 'name' => 'Website']);
        $conversation = Conversation::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'channel_id' => $channel->id,
            'customer_id' => $customer->id,
            'lead_id' => $lead->id,
            'external_id' => 'cw-99',
            'subject' => 'Color appointment',
            'status' => 'open',
            'priority' => 'normal',
        ]);
        Message::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_name' => 'Customer',
            'body' => 'Can I book color appointment tomorrow?',
            'external_id' => 'msg-99',
            'sent_at' => now(),
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

        return [$tenant, $conversation, $user];
    }
}