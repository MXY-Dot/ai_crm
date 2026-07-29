import { readFileSync } from 'node:fs'
import assert from 'node:assert/strict'
import test from 'node:test'
import { pageFromPath } from '../../resources/js/lib/pages'

test('maps routed URLs to dashboard page ids', () => {
    assert.equal(pageFromPath('/app'), 'overview')
    assert.equal(pageFromPath('/integrations'), 'integrations')
    assert.equal(pageFromPath('/unknown'), 'overview')
})

test('logs out through an Inertia visit', () => {
    const layout = readFileSync(new URL('../../resources/js/layouts/AppLayout.vue', import.meta.url), 'utf8')

    assert.match(layout, /router\.post\('\/logout', \{\}, \{\s*onFinish:/)
    assert.doesNotMatch(layout, /window\.location\.assign\(/)
})
