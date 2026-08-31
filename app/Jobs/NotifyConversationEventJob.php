<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ТЗ раздел 15 — real-time smart notifications. Same job/staff-selection
 * shape as NotifyVipContactJob, parameterized by event type instead of one
 * job per trigger, since these all resolve to the same "who gets told" answer
 * (owner/manager). Most types fire from AiWorkflow::process() (or, for
 * ai_knowledge_gap, from recordKnowledgeGap() mid-turn), gated on a
 * conversation label so a conversation never re-notifies for the same thing
 * on every message; 'operator_idle' fires from
 * NotifyIdleOperatorConversationsCommand instead, gated the same way via its
 * own 'operator_idle' label; 'waiting_too_long' fires from
 * NotifyWaitingTooLongConversationsCommand the same way, via 'waiting_too_long'.
 * VIP-customer is actually already covered separately -- see
 * AiWorkflow::process()'s NotifyVipContactJob dispatch on a VIP customer's
 * first message, just not part of this TYPES list. 'large_order' reads the
 * amount AiWorkflow::extractOrderAmount() wrote onto the conversation's own
 * Lead, so it needs 'lead' eager-loaded too (see content() below).
 */
class NotifyConversationEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public const TYPES = [
        'unhappy_customer', 'complaint', 'handoff_needed', 'lead_qualified', 'operator_idle',
        'wants_manager', 'competitor_mentioned', 'repeated_problem', 'ai_knowledge_gap', 'waiting_too_long',
        'large_order',
    ];

    public function __construct(
        private readonly int $tenantId,
        private readonly int $conversationId,
        private readonly string $eventType,
    ) {
    }

    public function handle(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        $conversation = Conversation::withoutGlobalScopes()->with(['customer', 'lead'])->find($this->conversationId);

        if (! $tenant || ! $conversation || ! in_array($this->eventType, self::TYPES, true)) {
            return;
        }

        [$title, $body, $priority] = $this->content($conversation);

        $staff = User::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', [User::ROLE_OWNER, User::ROLE_MANAGER])
            ->where('status', 'active')
            ->get();

        // ТЗ раздел 17 — "кнопка перехода в чат": a specific conversation deep
        // link, not just the generic inbox landing page. InboxWorkspace.vue
        // already reads exactly this `?conversation=` query param on mount.
        $actionUrl = '/inbox?conversation='.$conversation->id;

        foreach ($staff as $user) {
            $user->notify(new AppNotification($this->eventType, $title, $body, $actionUrl, $priority));
        }
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function content(Conversation $conversation): array
    {
        $name = $conversation->customer?->name ?: 'Клиент';

        return match ($this->eventType) {
            'unhappy_customer' => ['Клиент недоволен', "{$name} выразил недовольство в диалоге.", 'high'],
            'complaint' => ['Жалоба от клиента', "{$name} написал жалобу.", 'high'],
            'handoff_needed' => ['AI не может решить вопрос', "Диалог с «{$name}» передан оператору — AI не справился самостоятельно.", 'high'],
            'lead_qualified' => ['Клиент готов купить', "{$name} — квалифицированный лид, стоит связаться.", 'normal'],
            'operator_idle' => ['Оператор долго не отвечает', "Диалог с «{$name}» ждёт ответа оператора уже больше 15 минут.", 'high'],
            'wants_manager' => ['Клиент просит руководителя', "{$name} попросил связать с руководителем или живым человеком.", 'high'],
            'competitor_mentioned' => ['Риск потери клиента', "{$name} упомянул конкурента или более низкую цену в другом месте.", 'high'],
            'repeated_problem' => ['Клиент повторяет один вопрос', "{$name} уже несколько раз обращается по одной и той же теме — похоже, вопрос не решается.", 'normal'],
            'ai_knowledge_gap' => ['AI не нашёл ответ в базе знаний', "По вопросу от «{$name}» в базе знаний недостаточно информации.", 'normal'],
            'waiting_too_long' => ['Клиент долго ждёт ответа', "{$name} написал(а) и не получил(а) вообще никакого ответа уже больше 10 минут.", 'urgent'],
            'large_order' => ['Крупный заказ', "{$name} упомянул(а) сумму".($conversation->lead?->amount ? ' — '.number_format((float) $conversation->lead->amount, 0, ',', ' ') : '').", похоже на крупный заказ.", 'high'],
        };
    }
}
