<?php

namespace Database\Seeders;

use App\Models\AiAgent;
use App\Models\AiRun;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'demo'],
            ['name' => 'Gravity Demo', 'status' => 'active']
        );
        User::query()->updateOrCreate(
            ['email' => 'admin@gravity.test'],
            [
                'tenant_id' => null,
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'role' => User::ROLE_SUPER_ADMIN,
                'status' => 'active',
            ]
        );


        $owner = User::query()->updateOrCreate(
            ['email' => 'owner@gravity.test'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Demo Owner',
                'password' => Hash::make('password'),
                'role' => User::ROLE_OWNER,
                'status' => 'active',
            ]
        );

        $company = Company::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Gravity Beauty Studio'],
            [
                'industry' => 'beauty',
                'phone' => '+1 555 0100',
                'email' => 'hello@gravity.test',
                'timezone' => 'Asia/Karachi',
                'address' => '12 Demo Street, Beauty District',
                'working_hours' => ['summary' => 'Mon-Fri 10:00-19:00, Sat 11:00-16:00, Sunday closed'],
                'brand_settings' => [
                    'services' => 'Hair color from 80 USD; wedding makeup from 220 USD; consultation is free for first-time clients.',
                    'booking_rules' => 'Ask for service, preferred date, time window and phone before confirming a booking.',
                    'cancellation_policy' => 'Cancellations should be made 24 hours before the appointment. Deposits require operator approval.',
                ],
            ]
        );

        $customers = collect([
            ['name' => 'Amina Khan', 'phone' => '+92 300 100 2001', 'source' => 'telegram'],
            ['name' => 'Sara Malik', 'phone' => '+92 300 100 2002', 'source' => 'whatsapp'],
            ['name' => 'Noah Reed', 'email' => 'noah@example.com', 'source' => 'website'],
        ])->map(fn (array $data) => Customer::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'name' => $data['name']],
            $data + ['tags' => ['demo'], 'meta' => ['seeded' => true]]
        ));

        $leads = collect([
            ['title' => 'Hair color appointment', 'status' => 'new', 'score' => 72, 'source' => 'telegram'],
            ['title' => 'Wedding makeup package', 'status' => 'qualified', 'score' => 88, 'source' => 'whatsapp'],
            ['title' => 'Website consultation request', 'status' => 'new', 'score' => 54, 'source' => 'website'],
        ])->map(fn (array $data, int $index) => Lead::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'title' => $data['title']],
            $data + [
                'customer_id' => $customers[$index]->id,
                'assigned_user_id' => $owner->id,
                'ai_summary' => 'Demo lead created from an omnichannel conversation.',
            ]
        ));

        collect([
            ['title' => 'Call Amina with available slots', 'priority' => 'high', 'status' => 'open'],
            ['title' => 'Prepare wedding package offer', 'priority' => 'urgent', 'status' => 'in_progress'],
            ['title' => 'Review website chat transcript', 'priority' => 'normal', 'status' => 'open'],
        ])->each(fn (array $data, int $index) => CrmTask::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'title' => $data['title']],
            $data + ['lead_id' => $leads[$index]->id, 'assigned_user_id' => $owner->id]
        ));

        $channels = collect([
            ['provider' => 'telegram', 'name' => 'Telegram Bot', 'status' => 'connected'],
            ['provider' => 'whatsapp', 'name' => 'WhatsApp Business', 'status' => 'pending'],
            ['provider' => 'website', 'name' => 'Website Widget', 'status' => 'connected'],
        ])->map(fn (array $data) => Channel::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'provider' => $data['provider'], 'name' => $data['name']],
            $data + ['settings' => ['demo' => true], 'last_synced_at' => now()->subMinutes(15)]
        ));

        $conversations = collect([
            [
                'channel' => 0,
                'customer' => 0,
                'lead' => 0,
                'subject' => 'Client asks for color appointment slots',
                'status' => 'open',
                'priority' => 'high',
                'ai_summary' => 'Amina wants hair color this week, prefers evening slots and shared her phone.',
            ],
            [
                'channel' => 1,
                'customer' => 1,
                'lead' => 1,
                'subject' => 'Wedding package price request',
                'status' => 'pending_operator',
                'priority' => 'urgent',
                'ai_summary' => 'Sara needs a bridal makeup package, asks for deposit terms and available team dates.',
            ],
            [
                'channel' => 2,
                'customer' => 2,
                'lead' => 2,
                'subject' => 'Website consultation request',
                'status' => 'open',
                'priority' => 'normal',
                'ai_summary' => 'Noah asks about consultation duration and online booking flow.',
            ],
        ])->map(fn (array $data) => Conversation::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'subject' => $data['subject']],
            [
                'channel_id' => $channels[$data['channel']]->id,
                'customer_id' => $customers[$data['customer']]->id,
                'lead_id' => $leads[$data['lead']]->id,
                'assigned_user_id' => $owner->id,
                'status' => $data['status'],
                'priority' => $data['priority'],
                'last_message_at' => now()->subMinutes(8 + $data['channel'] * 9),
                'ai_summary' => $data['ai_summary'],
            ]
        ));

        $messageRows = [
            [0, 'customer', 'Amina Khan', 'Hi, do you have hair color appointments after 6 PM this week?'],
            [0, 'ai', 'Gravity AI', 'Yes, I found possible evening slots. Can I confirm your preferred day?'],
            [1, 'customer', 'Sara Malik', 'Please send wedding makeup package and deposit details.'],
            [1, 'ai', 'Gravity AI', 'I can prepare the package, but deposit policy needs operator approval.'],
            [2, 'customer', 'Noah Reed', 'Can I book a website consultation online?'],
            [2, 'ai', 'Gravity AI', 'Yes. I can collect contact details and create a follow-up task.'],
        ];

        foreach ($messageRows as $index => [$conversationIndex, $senderType, $senderName, $body]) {
            Message::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'conversation_id' => $conversations[$conversationIndex]->id, 'body' => $body],
                [
                    'sender_type' => $senderType,
                    'sender_name' => $senderName,
                    'sent_at' => now()->subMinutes(20 - $index),
                    'meta' => ['demo' => true],
                ]
            );
        }

        $agent = AiAgent::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'name' => 'Beauty Sales Assistant'],
            [
                'provider' => 'dify',
                'status' => 'active',
                'handoff_threshold' => 70,
                'instructions' => 'Answer briefly, extract service/date/phone, create handoff when payment or policy questions appear.',
                'settings' => ['knowledge_base' => 'beauty-demo'],
            ]
        );

        collect([
            ['conversation' => 0, 'lead' => 0, 'confidence' => 82, 'intent' => 'booking_request', 'next_action' => 'suggest_slots'],
            ['conversation' => 1, 'lead' => 1, 'confidence' => 64, 'intent' => 'pricing_request', 'next_action' => 'handoff_operator'],
            ['conversation' => 2, 'lead' => 2, 'confidence' => 76, 'intent' => 'consultation_booking', 'next_action' => 'collect_contact_details'],
        ])->each(fn (array $data) => AiRun::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'ai_agent_id' => $agent->id, 'conversation_id' => $conversations[$data['conversation']]->id],
            [
                'lead_id' => $leads[$data['lead']]->id,
                'status' => 'completed',
                'confidence' => $data['confidence'],
                'intent' => $data['intent'],
                'summary' => $conversations[$data['conversation']]->ai_summary,
                'next_action' => $data['next_action'],
                'started_at' => now()->subMinutes(12),
                'finished_at' => now()->subMinutes(11),
                'payload' => ['demo' => true],
            ]
        ));
    }
}