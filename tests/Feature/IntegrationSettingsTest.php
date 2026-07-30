<?php

namespace Tests\Feature;

use App\Models\AiAgent;
use App\Models\AuditLog;
use App\Models\AiRun;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Tenant;
use App\Support\Integrations\TenantIntegrationSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_integration_settings_and_secrets_are_masked(): void
    {
        [$tenant, $agent, $user] = $this->tenantSetup('owner');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->patchJson('/api/integration-settings', [
                'dify' => [
                    'url' => 'https://tenant-dify.example/v1',
                    'api_key' => 'tenant-secret-key',
                    'timeout' => 18,
                    'handoff_threshold' => 72,
                ],
                'chatwoot' => [
                    'url' => 'https://tenant-chatwoot.example',
                    'account_id' => 7,
                    'api_token' => 'chatwoot-api-token',
                    'webhook_secret' => 'chatwoot-secret',
                    'auto_reply_enabled' => true,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('dify.url', 'https://tenant-dify.example/v1')
            ->assertJsonPath('dify.api_key_configured', true)
            ->assertJsonPath('dify.api_key_mask', '*************-key')
            ->assertJsonPath('dify.timeout', 18)
            ->assertJsonPath('dify.handoff_threshold', 72)
            ->assertJsonPath('chatwoot.url', 'https://tenant-chatwoot.example')
            ->assertJsonPath('chatwoot.account_id', 7)
            ->assertJsonPath('chatwoot.api_token_configured', true)
            ->assertJsonPath('chatwoot.api_token_mask', '**************oken')
            ->assertJsonPath('chatwoot.webhook_secret_configured', true)
            ->assertJsonPath('chatwoot.webhook_secret_mask', '***********cret')
            ->assertJsonPath('chatwoot.auto_reply_enabled', true);

        $tenant->refresh();
        $agent->refresh();

        $this->assertNotSame('tenant-secret-key', data_get($tenant->settings, 'integrations.dify.api_key'));
        $this->assertStringStartsWith('enc:v1:', data_get($tenant->settings, 'integrations.dify.api_key'));
        $this->assertSame('tenant-secret-key', app(TenantIntegrationSettings::class)->difyApiKey($tenant, false));
        $this->assertSame('https://tenant-chatwoot.example', data_get($tenant->settings, 'integrations.chatwoot.url'));
        $this->assertSame(7, data_get($tenant->settings, 'integrations.chatwoot.account_id'));
        $this->assertNotSame('chatwoot-api-token', data_get($tenant->settings, 'integrations.chatwoot.api_token'));
        $this->assertStringStartsWith('enc:v1:', data_get($tenant->settings, 'integrations.chatwoot.api_token'));
        $this->assertSame('chatwoot-api-token', app(TenantIntegrationSettings::class)->chatwootApiToken($tenant, false));
        $this->assertNotSame('chatwoot-secret', data_get($tenant->settings, 'integrations.chatwoot.webhook_secret'));
        $this->assertStringStartsWith('enc:v1:', data_get($tenant->settings, 'integrations.chatwoot.webhook_secret'));
        $this->assertSame('chatwoot-secret', app(TenantIntegrationSettings::class)->chatwootWebhookSecret($tenant, false));
        $this->assertTrue(data_get($tenant->settings, 'integrations.chatwoot.auto_reply_enabled'));
        $this->assertSame(72, $agent->handoff_threshold);
        $this->assertDatabaseHas(AuditLog::class, [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'action' => 'integration_settings.updated',
            'entity_type' => Tenant::class,
            'entity_id' => $tenant->id,
        ]);
    }


    public function test_empty_secret_fields_keep_existing_values(): void
    {
        [$tenant, , $user] = $this->tenantSetup('owner');

        $tenant->forceFill([
            'settings' => [
                'integrations' => [
                    'dify' => ['api_key' => app(TenantIntegrationSettings::class)->encrypt('saved-dify-key')],
                    'chatwoot' => [
                        'api_token' => app(TenantIntegrationSettings::class)->encrypt('saved-chatwoot-token'),
                        'webhook_secret' => app(TenantIntegrationSettings::class)->encrypt('saved-chatwoot-secret'),
                    ],
                    'telegram' => [
                        'bot_token' => app(TenantIntegrationSettings::class)->encrypt('saved-telegram-token'),
                        'webhook_secret' => app(TenantIntegrationSettings::class)->encrypt('saved-telegram-secret'),
                    ],
                ],
            ],
        ])->save();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->patchJson('/api/integration-settings', [
                'dify' => ['api_key' => ''],
                'chatwoot' => ['api_token' => '', 'webhook_secret' => ''],
                'telegram' => ['bot_token' => '', 'webhook_secret' => ''],
            ])
            ->assertOk()
            ->assertJsonPath('dify.api_key_configured', true)
            ->assertJsonPath('chatwoot.api_token_configured', true)
            ->assertJsonPath('chatwoot.webhook_secret_configured', true)
            ->assertJsonPath('telegram.bot_token_configured', true)
            ->assertJsonPath('telegram.webhook_secret_configured', true);

        $tenant->refresh();
        $settings = app(TenantIntegrationSettings::class);

        $this->assertSame('saved-dify-key', $settings->difyApiKey($tenant, false));
        $this->assertSame('saved-chatwoot-token', $settings->chatwootApiToken($tenant, false));
        $this->assertSame('saved-chatwoot-secret', $settings->chatwootWebhookSecret($tenant, false));
        $this->assertSame('saved-telegram-token', $settings->telegramBotToken($tenant));
        $this->assertSame('saved-telegram-secret', $settings->telegramWebhookSecret($tenant));
    }
    public function test_existing_plain_tenant_secrets_remain_readable(): void
    {
        [$tenant] = $this->tenantSetup('owner');
        $tenant->forceFill([
            'settings' => [
                'integrations' => [
                    'dify' => ['api_key' => 'plain-dify-key'],
                    'chatwoot' => ['api_token' => 'plain-chatwoot-token', 'webhook_secret' => 'plain-chatwoot-secret'],
                ],
            ],
        ])->save();

        $settings = app(TenantIntegrationSettings::class);

        $this->assertSame('plain-dify-key', $settings->difyApiKey($tenant, false));
        $this->assertSame('plain-chatwoot-token', $settings->chatwootApiToken($tenant, false));
        $this->assertSame('plain-chatwoot-secret', $settings->chatwootWebhookSecret($tenant, false));
    }
    public function test_unset_dify_url_is_returned_as_null(): void
    {
        config(['services.dify.url' => null]);
        [, , $user] = $this->tenantSetup('owner');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->getJson('/api/integration-settings')
            ->assertOk()
            ->assertJsonPath('dify.url', null);
    }


    public function test_operator_cannot_update_integration_settings(): void
    {
        [, , $user] = $this->tenantSetup('operator');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->patchJson('/api/integration-settings', ['dify' => ['timeout' => 20]])
            ->assertForbidden();
    }

    public function test_integration_settings_are_isolated_between_tenants(): void
    {
        [$firstTenant, , $firstUser] = $this->tenantSetup('owner');
        $secondTenant = Tenant::query()->create(['name' => 'Second Workspace', 'slug' => 'second']);
        $secondCompany = Company::query()->create(['tenant_id' => $secondTenant->id, 'name' => 'Second Studio']);
        AiAgent::query()->create([
            'tenant_id' => $secondTenant->id,
            'company_id' => $secondCompany->id,
            'name' => 'Second Agent',
            'handoff_threshold' => 60,
        ]);
        $secondUser = User::factory()->create(['tenant_id' => $secondTenant->id, 'role' => 'owner']);

        $this->actingAs($firstUser)
            ->withHeader('X-Tenant-Id', $firstTenant->slug)
            ->patchJson('/api/integration-settings', [
                'dify' => [
                    'url' => 'https://first-dify.example/v1',
                    'api_key' => 'first-dify-key',
                    'timeout' => 9,
                    'handoff_threshold' => 71,
                ],
                'chatwoot' => [
                    'url' => 'https://first-chatwoot.example',
                    'account_id' => 11,
                    'api_token' => 'first-chatwoot-token',
                    'webhook_secret' => 'first-chatwoot-secret',
                ],
            ])
            ->assertOk();

        $this->actingAs($secondUser)
            ->withHeader('X-Tenant-Id', $secondTenant->slug)
            ->getJson('/api/integration-settings')
            ->assertOk()
            ->assertJsonPath('dify.url', config('services.dify.url'))
            ->assertJsonPath('dify.api_key_configured', false)
            ->assertJsonPath('chatwoot.api_token_configured', false)
            ->assertJsonPath('chatwoot.webhook_url', url('/api/chatwoot/webhook?tenant_slug=second'));

        $this->actingAs($secondUser)
            ->withHeader('X-Tenant-Id', $secondTenant->slug)
            ->patchJson('/api/integration-settings', [
                'dify' => [
                    'url' => 'https://second-dify.example/v1',
                    'api_key' => 'second-dify-key',
                ],
                'chatwoot' => [
                    'url' => 'https://second-chatwoot.example',
                    'account_id' => 22,
                    'api_token' => 'second-chatwoot-token',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('dify.url', 'https://second-dify.example/v1')
            ->assertJsonPath('chatwoot.url', 'https://second-chatwoot.example')
            ->assertJsonPath('chatwoot.account_id', 22);

        $this->actingAs($firstUser)
            ->withHeader('X-Tenant-Id', $firstTenant->slug)
            ->getJson('/api/integration-settings')
            ->assertOk()
            ->assertJsonPath('dify.url', 'https://first-dify.example/v1')
            ->assertJsonPath('chatwoot.url', 'https://first-chatwoot.example')
            ->assertJsonPath('chatwoot.account_id', 11)
            ->assertJsonPath('chatwoot.webhook_url', url('/api/chatwoot/webhook?tenant_slug=demo'));
    }
    public function test_chatwoot_webhook_uses_tenant_secret(): void
    {
        [$tenant] = $this->tenantSetup('owner');
        $tenant->forceFill(['settings' => ['integrations' => ['chatwoot' => ['webhook_secret' => 'tenant-secret']]]])->save();

        $this->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/chatwoot/webhook', ['message' => ['content' => 'Hello']])
            ->assertUnauthorized();

        $this->withHeaders(['X-Tenant-Id' => 'demo', 'X-Webhook-Secret' => 'tenant-secret'])
            ->postJson('/api/chatwoot/webhook', ['message' => ['content' => 'Hello']])
            ->assertCreated();
    }

    public function test_dify_client_uses_global_url_and_tenant_api_key(): void
    {
        config(['services.dify.url' => 'https://tenant-dify.example/v1', 'services.dify.api_key' => '']);
        Http::fake([
            'tenant-dify.example/*' => Http::response([
                'answer' => json_encode([
                    'confidence' => 93,
                    'intent' => 'tenant_dify_booking',
                    'summary' => 'Tenant Dify handled the booking.',
                    'next_action' => 'confirm_booking',
                    'handoff_required' => false,
                ]),
            ]),
        ]);

        [$tenant] = $this->tenantSetup('owner');
        $tenant->forceFill([
            'settings' => [
                'integrations' => [
                    'dify' => [
                        'url' => 'https://tenant-dify.example/v1',
                        'api_key' => 'tenant-key',
                        'timeout' => 7,
                    ],
                ],
            ],
        ])->save();

        $this->withHeader('X-Tenant-Id', 'demo')->postJson('/api/chatwoot/webhook', [
            'provider' => 'website',
            'conversation' => ['id' => 'cw-tenant-dify', 'subject' => 'Tenant Dify booking'],
            'sender' => ['name' => 'Settings Client'],
            'message' => ['id' => 'msg-tenant-dify', 'content' => 'Please book tomorrow.'],
        ])->assertCreated()->assertJsonPath('ai_run.intent', 'tenant_dify_booking');

        $this->assertDatabaseHas(AiRun::class, [
            'tenant_id' => $tenant->id,
            'intent' => 'tenant_dify_booking',
            'confidence' => 93,
        ]);

        $this->assertDatabaseHas(Lead::class, [
            'tenant_id' => $tenant->id,
            'title' => 'Tenant Dify booking',
            'score' => 93,
        ]);
    }

    public function test_owner_can_test_dify_connection(): void
    {
        config(['services.dify.url' => 'https://tenant-dify.example/v1']);

        Http::fake([
            'tenant-dify.example/*' => Http::response(['answer' => 'ok']),
        ]);

        [$tenant, , $user] = $this->tenantSetup('owner');
        $tenant->forceFill([
            'settings' => [
                'integrations' => [
                    'dify' => [
                        'url' => 'https://tenant-dify.example/v1',
                        'api_key' => 'tenant-key',
                        'timeout' => 6,
                    ],
                ],
            ],
        ])->save();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/integration-settings/test', ['provider' => 'dify'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('provider', 'dify')
            ->assertJsonPath('status', 'connected');

        $this->assertDatabaseHas(AuditLog::class, [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'action' => 'integration_connection.tested',
            'entity_type' => Tenant::class,
            'entity_id' => $tenant->id,
        ]);
    }

    public function test_dify_connection_test_reports_missing_credentials(): void
    {
        [, , $user] = $this->tenantSetup('owner');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/integration-settings/test', ['provider' => 'dify'])
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'missing_credentials');
    }

    public function test_owner_can_test_chatwoot_api_connection(): void
    {
        config(['services.chatwoot.url' => 'https://chatwoot.example']);

        Http::fake([
            'chatwoot.example/api/v1/accounts/7/inboxes' => Http::response(['payload' => []]),
        ]);

        [$tenant, , $user] = $this->tenantSetup('owner');
        $tenant->forceFill([
            'settings' => [
                'integrations' => [
                    'chatwoot' => [
                        'url' => 'https://chatwoot.example',
                        'account_id' => 7,
                        'api_token' => 'chatwoot-api-token',
                        'webhook_secret' => 'tenant-secret',
                    ],
                ],
            ],
        ])->save();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/integration-settings/test', ['provider' => 'chatwoot'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('provider', 'chatwoot')
            ->assertJsonPath('status', 'connected')
            ->assertJsonPath('meta.account_id', '7')
            ->assertJsonPath('meta.tenant_query', 'tenant_slug=demo');

        Http::assertSent(fn ($request) => $request->hasHeader('api_access_token', 'chatwoot-api-token'));
    }

    public function test_operator_cannot_test_integration_connections(): void
    {
        [, , $user] = $this->tenantSetup('operator');

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/integration-settings/test', ['provider' => 'chatwoot'])
            ->assertForbidden();
    }

    public function test_integration_connection_test_is_rate_limited(): void
    {
        [, , $user] = $this->tenantSetup('owner');

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)
                ->withHeader('X-Tenant-Id', 'demo')
                ->postJson('/api/integration-settings/test', ['provider' => 'dify'])
                ->assertUnprocessable();
        }

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', 'demo')
            ->postJson('/api/integration-settings/test', ['provider' => 'dify'])
            ->assertTooManyRequests();
    }
    private function tenantSetup(string $role): array
    {
        $tenant = Tenant::query()->create(['name' => 'Demo', 'slug' => 'demo']);
        $company = Company::query()->create(['tenant_id' => $tenant->id, 'name' => 'Studio']);
        $agent = AiAgent::query()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Main Agent',
            'handoff_threshold' => 60,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);

        return [$tenant, $agent, $user];
    }
}
