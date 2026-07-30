import assert from 'node:assert/strict'
import test from 'node:test'
import { createSSRApp, h } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createServer } from 'vite'

test('shared Button renders a native submit button', async () => {
    const vite = await createServer({
        appType: 'custom',
        logLevel: 'silent',
        server: { middlewareMode: true },
    })

    try {
        const { default: Button } = await vite.ssrLoadModule(
            '/resources/js/components/ui/button/Button.vue',
        )
        const app = createSSRApp({
            render: () => h(Button, { type: 'submit' }, () => 'Save'),
        })
        const html = await renderToString(app)

        assert.match(html, /^<button\b/)
        assert.match(html, /\btype="submit"/)
    } finally {
        await vite.close()
    }
})
