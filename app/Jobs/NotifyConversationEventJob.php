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
 * (owner/manager). Four of the five fire from AiWorkflow::process(), gated on
 * a conversation label ('unhappy'/'complaint'/'handoff') or the lead's status
 * transition into 'qualified' so a conversation never re-notifies for the
 * same thing on every message; 'operator_idle' fires from
 * NotifyIdleOperatorConversationsCommand instead, gated the same way via its
 * own 'operator_idle' label.
 */
class NotifyConversationEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public const TYPES = ['unhappy_customer', 'complaint', 'handoff_needed', 'lead_qualified', 'operator_idle'];

    public function __construct(
        private readonly int $tenantId,
        private readonly int $conversationId,
        private readonly string $eventType,
    ) {
    }

    public function handle(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        $conversation = Conversation::withoutGlobalScopes()->with('customer')->find($this->conversationId);

        if (! $tenant || ! $conversation || ! in_array($this->eventType, self::TYPES, true)) {
            return;
        }

        [$title, $body, $priority] = $this->content($conversation);

        $staff = User::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', [User::ROLE_OWNER, User::ROLE_MANAGER])
            ->where('status', 'active')
            ->get();

        foreach ($staff as $user) {
            $user->notify(new AppNotification($this->eventType, $title, $body, '/inbox', $priority));
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
        };
    }
}
