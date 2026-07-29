import { readFileSync } from 'node:fs'
import assert from 'node:assert/strict'
import test from 'node:test'
import { createPinia, setActivePinia } from 'pinia'
import { pageFromPath, pathForRecord } from '../../resources/js/lib/pages'

test('maps routed URLs to dashboard page ids', () => {
    assert.equal(pageFromPath('/app'), 'overview')
    assert.equal(pageFromPath('/integrations'), 'integrations')
    assert.equal(pageFromPath('/unknown'), 'overview')
})

test('maps record kinds to routed workspaces', () => {
    assert.equal(pathForRecord('lead'), '/leads')
    assert.equal(pathForRecord('customer'), '/customers')
    assert.equal(pathForRecord('conversation'), '/inbox')
})

test('exposes selected lead and customer state for routed workspaces', async () => {
    globalThis.document ??= { addEventListener: () => {}, querySelector: () => null } as unknown as Document

    const { useCrmDashboardStore } = await import('../../resources/js/stores/crmDashboard')

    setActivePinia(createPinia())

    const store = useCrmDashboardStore()

    assert.equal(store.selectedLeadId, null)
    assert.equal(store.selectedCustomerId, null)
})

test('logs out through an Inertia visit', () => {
    const layout = readFileSync(new URL('../../resources/js/layouts/AppLayout.vue', import.meta.url), 'utf8')

    assert.match(layout, /router\.post\('\/logout', \{\}, \{\s*onFinish:/)
    assert.doesNotMatch(layout, /window\.location\.assign\(/)
})
