# 04 - Verify handoff and task board

**What to build:** confirm risky or unclear customer messages create an operator handoff and a task that can be worked through.

**Blocked by:** 02 - Verify Chatwoot to CRM flow.

**Status:** resolved

- [x] A risky or unclear customer message creates an AI handoff item.
- [x] The handoff item shows confidence, intent, summary, lead and conversation context.
- [x] A linked task appears in the CRM task board.
- [x] The operator can start and complete the linked task.

## Evidence

- `AiRun#15` is linked to `conversation_id=4` and `lead_id=4`, with `confidence=65`, `intent=general_question`, `next_action=handoff_operator`, and `payload.handoff_required=true`.
- `CrmTask#4` is linked to `lead_id=4`, titled `AI handoff: Chatwoot conversation #1`, with `priority=high`.
- `AiWorkflow::handoffTask()` creates a high-priority CRM task whenever the AI decision requires handoff.
- `DashboardData` returns tasks with `lead_id`, AI runs with lead/conversation relations, and the handoff center matches runs to tasks by lead.
- `AiHandoffCenter.vue` displays confidence, intent, summary, lead, conversation, next action, and start/done controls for the linked open task.
- `TaskList.vue` exposes the same start/done task controls on the CRM task board.
- Verified `CrmTask#4` can move `done -> in_progress -> done` through the model path used by the task board state.
- Added `lead_id: number | null` to the frontend `Task` type because the UI already depends on that API field.
- `npm run build` passes.

## Note

- Direct HTTP PATCH to `http://127.0.0.1:8000/api/tasks/4` was not run because the local Laravel HTTP server was not listening on port 8000 during this pass.