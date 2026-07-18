# 02 - Verify Chatwoot to CRM flow

**What to build:** confirm a real website-widget message appears in CRM Inbox with linked customer, lead and conversation context.

**Blocked by:** 01 - Verify local startup.

**Status:** resolved

- [x] A message sent from the Chatwoot website widget appears in CRM Inbox.
- [x] The conversation queue shows the new conversation.
- [x] The conversation includes customer, lead and AI summary context.
- [x] Customer and lead links open the correct CRM workspace views.

## Result

Checked on 2026-07-13:

- Chatwoot API returned 1 conversation; conversation `1` was found with status `open`.
- CRM has Chatwoot-linked conversation `conversation_id=4`, `external_id=1`.
- CRM conversation `4` has `customer_id=4`, `lead_id=4`, and 26 messages.
- Latest customer message is stored in CRM: `I love anime do you love it,`.
- Linked lead has `ai_summary` set, so Inbox can show AI context.

Next step: continue with `03-ai-draft-flow.md`.
