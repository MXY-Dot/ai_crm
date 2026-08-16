<?php

namespace App\Support\Integrations;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Platform-wide (not per-tenant) settings. WERO holds one key per LLM provider
 * (Groq, OpenAI, Anthropic, Google/Gemini, DeepSeek), managed centrally from
 * Super Admin — tenants no longer bring their own keys; they just pick a model
 * their plan allows (see AiWorkflow::PLAN_PROVIDERS) and WERO's own account is
 * billed. TenantIntegrationSettings' per-provider getters still check for a rare
 * tenant-level override first, falling back here. Keeps its own tiny
 * encrypt/decrypt/mask (same "enc:v1:" + Crypt::encryptString scheme as
 * TenantIntegrationSettings) rather than depending on that class, to avoid a
 * circular dependency between the two settings services.
 */
class PlatformSettings
{
    private const PREFIX = 'enc:v1:';

    /** Maps a provider id to its config/services.php block name (google's LLM key lives under 'gemini' — 'google' is already the OAuth client config). */
    private const CONFIG_KEYS = [
        'groq' => 'groq',
        'openai' => 'openai',
        'anthropic' => 'anthropic',
        'google' => 'gemini',
        'deepseek' => 'deepseek',
    ];

    public function llmApiKey(string $provider): string
    {
        $configKey = self::CONFIG_KEYS[$provider] ?? null;

        if (! $configKey) {
            return '';
        }

        return $this->get("llm.{$provider}.api_key") ?? (string) config("services.{$configKey}.api_key", '');
    }

    public function setLlmApiKey(string $provider, string $value): void
    {
        if (! array_key_exists($provider, self::CONFIG_KEYS)) {
            return;
        }

        $this->set("llm.{$provider}.api_key", $value);
    }

    public function groqApiKey(): string
    {
        return $this->llmApiKey('groq');
    }

    public function primaryLlmProvider(): string
    {
        return $this->get('llm.primary_provider', false) ?? 'groq';
    }

    public function setPrimaryLlmProvider(string $provider): void
    {
        $this->set('llm.primary_provider', $provider, encrypt: false);
    }

    /** Maps a provider to a sensible default model — see AiAgentSettingsForm.vue/CreateAgentDialog.vue's MODEL_OPTIONS for the canonical list these strings are drawn from. */
    private const DEFAULT_MODEL_BY_PROVIDER = [
        'deepseek' => 'deepseek-chat',
        'groq' => 'openai/gpt-oss-120b',
        'openai' => 'gpt-4o-mini',
        'anthropic' => 'claude-3-5-haiku-latest',
        'google' => 'gemini-1.5-flash',
    ];

    /**
     * A new AI agent with no model chosen used to just sit dumb forever (silently
     * falling back to the local keyword-matching engine, with no signal to the
     * tenant that anything was missing) — this is what a brand-new tenant gets by
     * default, so it has to work with zero manual setup. Defaults to whichever
     * model matches the platform's current primary provider.
     */
    public function defaultModel(): string
    {
        return self::DEFAULT_MODEL_BY_PROVIDER[$this->primaryLlmProvider()] ?? self::DEFAULT_MODEL_BY_PROVIDER['groq'];
    }

    public function backupLlmProvider(): ?string
    {
        return $this->get('llm.backup_provider', false);
    }

    public function setBackupLlmProvider(?string $provider): void
    {
        if ($provider === null) {
            PlatformSetting::query()->where('key', 'llm.backup_provider')->delete();

            return;
        }

        $this->set('llm.backup_provider', $provider, encrypt: false);
    }

    public function mask(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return str_repeat('*', max(4, strlen($value) - 4)).substr($value, -4);
    }

    private function get(string $key, bool $encrypted = true): ?string
    {
        $row = PlatformSetting::query()->where('key', $key)->first();

        if (! $row || $row->value === null || $row->value === '') {
            return null;
        }

        return $encrypted ? $this->decrypt($row->value) : $row->value;
    }

    private function set(string $key, string $value, bool $encrypt = true): void
    {
        PlatformSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $encrypt ? $this->encrypt($value) : $value]
        );
    }

    private function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return self::PREFIX.Crypt::encryptString($value);
    }

    private function decrypt(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        if (! str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, strlen(self::PREFIX)));
        } catch (Throwable) {
            return '';
        }
    }
}
