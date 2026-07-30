import assert from 'node:assert/strict'
import test from 'node:test'

globalThis.document ??= { addEventListener: () => {}, createElement: () => ({}), querySelector: () => null } as unknown as Document

test('builds a complete integration settings payload from rendered fields', async () => {
    const dashboard: Record<string, unknown> = await import('../../resources/js/stores/crmDashboard')

    assert.equal(typeof dashboard.buildIntegrationSettingsPayload, 'function')

    const build = dashboard.buildIntegrationSettingsPayload as (form: Record<string, unknown>) => unknown

    assert.deepEqual(build({
        difyApiKey: 'dify-secret',
        difyTimeout: 18,
        handoffThreshold: 72,
        chatwootAccountId: 7,
        chatwootApiToken: 'chatwoot-token',
        chatwootSecret: 'chatwoot-secret',
        chatwootAutoReply: true,
        telegramBotToken: 'telegram-token',
        telegramSecret: 'telegram-secret',
        telegramAutoReply: true,
    }), {
        dify: {
            api_key: 'dify-secret',
            timeout: 18,
            handoff_threshold: 72,
        },
        chatwoot: {
            account_id: 7,
            api_token: 'chatwoot-token',
            webhook_secret: 'chatwoot-secret',
            auto_reply_enabled: true,
        },
        telegram: {
            bot_token: 'telegram-token',
            webhook_secret: 'telegram-secret',
            auto_reply_enabled: true,
        },
    })
})

test('surfaces a successful connection message through the dashboard toast', async () => {
    const { createPinia, setActivePinia } = await import('pinia')
    const { useCrmDashboardStore } = await import('../../resources/js/stores/crmDashboard')
    const originalFetch = globalThis.fetch

    globalThis.window = { setTimeout: () => 0 } as unknown as Window & typeof globalThis
    globalThis.fetch = async () => new Response(JSON.stringify({
        ok: true,
        provider: 'dify',
        status: 'connected',
        message: 'Dify connection succeeded.',
        checked_at: '2026-07-30T00:00:00Z',
        meta: {},
    }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    })

    try {
        setActivePinia(createPinia())
        const store = useCrmDashboardStore()
        store.tenant = { id: 1, name: 'Demo', slug: 'demo', status: 'active' }

        const result = await store.testIntegrationConnection({ provider: 'dify' })

        assert.equal(result.message, 'Dify connection succeeded.')
        assert.deepEqual(store.toasts.map(({ tone, message }) => ({ tone, message })), [{
            tone: 'success',
            message: 'Dify connection succeeded.',
        }])
    } finally {
        globalThis.fetch = originalFetch
    }
})

test('surfaces a safe failed connection message without exposing submitted secrets', async () => {
    const { createPinia, setActivePinia } = await import('pinia')
    const { useCrmDashboardStore } = await import('../../resources/js/stores/crmDashboard')
    const originalFetch = globalThis.fetch

    globalThis.fetch = async () => new Response(JSON.stringify({ message: 'Chatwoot credentials are missing.' }), {
        status: 422,
        headers: { 'Content-Type': 'application/json' },
    })

    try {
        setActivePinia(createPinia())
        const store = useCrmDashboardStore()
        store.tenant = { id: 1, name: 'Demo', slug: 'demo', status: 'active' }

        await assert.rejects(() => store.testIntegrationConnection({
            provider: 'chatwoot',
            chatwoot: { api_token: 'never-show-this-secret' },
        }), { message: 'Chatwoot credentials are missing.' })

        assert.deepEqual(store.toasts.map(({ tone, message }) => ({ tone, message })), [{
            tone: 'error',
            message: 'Chatwoot credentials are missing.',
        }])
        assert.doesNotMatch(JSON.stringify(store.toasts), /never-show-this-secret/)
    } finally {
        globalThis.fetch = originalFetch
    }
})
