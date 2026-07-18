# Gravity AI CRM - Manual QA Checklist

Use this checklist before calling the MVP ready for a real demo.

## Environment

- [ ] Laravel app opens at `http://localhost:8000`.
- [ ] Chatwoot opens at `http://127.0.0.1:3000`.
- [ ] Dify opens at `http://localhost:8080`.
- [ ] CRM Settings show Dify connected.
- [ ] CRM Settings show Chatwoot connected.

## Chatwoot to CRM

- [ ] Send a message from the Chatwoot website widget.
- [ ] Open CRM Inbox.
- [ ] New conversation appears in the queue.
- [ ] Conversation has customer, lead and AI summary.
- [ ] Customer and lead links open the CRM page.

## AI Draft

- [ ] Ask a customer question that needs business context.
- [ ] CRM creates an AI draft message.
- [ ] AI draft does not auto-send to the customer.
- [ ] Operator can insert the draft into reply box.
- [ ] Operator can send the reply to Chatwoot.

## Handoff

- [ ] Send a risky/unclear message from customer.
- [ ] AI handoff appears in AI workspace.
- [ ] Handoff shows confidence, intent, summary, lead and conversation.
- [ ] Linked task appears in CRM task board.
- [ ] Operator can start and complete the task.

## CRM Workspace

- [ ] Customer card opens from CRM customer list.
- [ ] Customer card shows linked leads and conversations.
- [ ] Lead detail opens from pipeline.
- [ ] Lead detail shows linked tasks and conversations.
- [ ] Task board groups tasks by open, in progress and done.

## Knowledge Base

- [ ] Add manual FAQ text.
- [ ] Document status becomes indexed.
- [ ] Dify answer uses profile or knowledge context.

## Final Checks

- [ ] Run `npm run build`.
- [ ] Run `php artisan test`.
- [ ] Run `php artisan optimize:clear`.
- [ ] Update `tasks/implementation_log.md`.