# 01 - Verify local startup

**What to build:** confirm the local demo environment starts cleanly and the CRM can see the required external service configuration.

**Blocked by:** None - can start immediately.

**Status:** resolved

- [x] Laravel CRM opens at `http://localhost:8000`.
- [x] Chatwoot UI opens at `http://127.0.0.1:3000` in a browser.
- [x] Dify opens at `http://127.0.0.1:8080`.
- [x] CRM Settings have Dify credentials and the built-in Dify connection test passes.
- [x] CRM Settings have Chatwoot credentials and the built-in Chatwoot connection test passes.

## Result

Checked on 2026-07-13:

- CRM returned HTTP 200 at `http://localhost:8000`.
- Dify returned HTTP 200 at `http://127.0.0.1:8080`.
- Added missing tenant Dify API URL: `http://127.0.0.1:8080/v1`.
- Changed tenant Chatwoot URL from `localhost:3000` to `127.0.0.1:3000` because local cURL timed out on `localhost`.
- Built-in Dify connection test passed: `connected`.
- Built-in Chatwoot connection test passed: `connected`.
- Chatwoot API is reachable, but CLI checks for `/` and `/app/login` timed out. Browser UI still needs visual confirmation by the user.

Follow-up checked after Chatwoot startup fixes:

- `http://127.0.0.1:3000/app/login` returned HTTP 200 in ~0.21s.
- Rails can fetch Vite client assets from `http://vite:3036/vite-dev/@vite/client`.

Next step: continue with `02-chatwoot-to-crm.md`.

