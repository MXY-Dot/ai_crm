# 03 - Verify AI draft flow

**What to build:** confirm AI replies stay operator-controlled from generation through final Chatwoot delivery.

**Blocked by:** 02 - Verify Chatwoot to CRM flow.

**Status:** resolved

- [x] A customer question that needs business context can generate an AI draft.
- [x] The AI draft is saved inside CRM and is not sent automatically.
- [x] The operator can insert the draft into the reply box.
- [x] The operator can send the final reply to Chatwoot.

## Result

Checked on 2026-07-13:

- CRM conversation `4` has AI draft messages, including `message#32` with `meta.draft=true`, `engine=dify`, and an `ai_run_id`.
- `ConversationAiDraftController` runs `AiWorkflow` and returns `draft_message`; it does not call `ChatwootClient`.
- `ConversationReplyController` is the separate manual delivery path and calls `ChatwootClient::sendOutgoingMessage`.
- Inbox UI wires `Use draft` to insert the draft into `replyBody` and `Send to Chatwoot` to `replyToConversation`.
- CRM has operator outgoing messages with Chatwoot payload/meta, including `message#28` with `direction=outgoing`.

Next step: continue with `04-handoff-task-board.md`.
