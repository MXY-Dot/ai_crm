import assert from 'node:assert/strict'
import test from 'node:test'
import { pageFromPath } from '../../resources/js/lib/pages'

test('maps routed URLs to dashboard page ids', () => {
    assert.equal(pageFromPath('/app'), 'overview')
    assert.equal(pageFromPath('/integrations'), 'integrations')
    assert.equal(pageFromPath('/unknown'), 'overview')
})
