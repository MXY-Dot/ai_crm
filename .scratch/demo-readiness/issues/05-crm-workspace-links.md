# 05 - Verify CRM workspace links

**What to build:** confirm the CRM workspace behaves as one connected operator surface for customers, leads, conversations and tasks.

**Blocked by:** 02 - Verify Chatwoot to CRM flow.

**Status:** resolved

- [x] A customer card opens from the CRM customer list.
- [x] The customer card shows linked leads and conversations.
- [x] A lead detail opens from the lead pipeline.
- [x] The lead detail shows linked tasks and conversations.
- [x] The task board groups tasks by open, in progress and done.

## Evidence

- `CustomerList.vue` selects a customer by emitting `select`, and `CrmPage.vue` stores the selected customer id.
- `CustomerProfilePanel.vue` receives and displays the selected customer plus linked leads and conversations.
- `LeadPipeline.vue` emits the selected lead, and `CrmPage.vue` stores selected lead id and syncs selected customer id from `lead.customer_id`.
- `LeadDetailPanel.vue` receives and displays the selected lead plus linked tasks and conversations.
- `TaskList.vue` groups tasks into `open`, `in_progress`, and `done` columns.
- Fixed `CrmPage.vue` to pass all `tasks` into lead detail and task board instead of only `openTasks`, so done tasks are visible on the CRM board.
- Added missing frontend fields: `Lead.customer_id` and `Task.lead_id` in `crmDashboard.ts`.
- Demo data now has task coverage for all visible board states: `open=1`, `in_progress=1`, `done=2`.
- Verified Chatwoot-linked CRM chain in data: `Customer#4 yakubovm533` -> `Lead#4 Chatwoot conversation #1` -> `Conversation#4` and `CrmTask#4`.
- `npm run build` passes.