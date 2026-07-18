# 06 - Verify Knowledge Base context

**What to build:** confirm manual Knowledge Base content can be indexed and used as business context by the AI workflow.

**Blocked by:** 01 - Verify local startup.

**Status:** resolved

- [x] Manual FAQ text can be added from the CRM.
- [x] The Knowledge Base document status becomes indexed.
- [x] A later AI answer uses company profile or Knowledge Base context.

## Evidence

- `POST api/knowledge-documents/index-text` exists and is wired to `KnowledgeDocumentController@indexText`.
- `KnowledgeBasePanel.vue` submits manual text through `store.indexKnowledgeText()` to `/api/knowledge-documents/index-text`.
- `KnowledgeIndexer::indexText()` creates a `KnowledgeDocument` with `status=indexed`, `indexed_at`, summary, and one or more `KnowledgeChunk` rows.
- Created/verified `KnowledgeDocument#1` titled `Demo FAQ: service rules`, `source_type=faq`, `status=indexed`, `chunks_count=1`.
- The indexed chunk contains business rules: hair color starts from 80 USD, wedding makeup starts from 220 USD, first consultation is free, booking requires service/date/time/phone, deposits/cancellations require operator approval.
- `DashboardData` returns the document to the CRM dashboard with `chunks_count=1`.
- `DifyClient` sends both `knowledge_context` and `business_profile` in the Dify `inputs` payload.
- Reflected `DifyClient::knowledgeContext()` for the active demo AI agent and confirmed it includes the indexed FAQ text.
- `npm run build` passes.