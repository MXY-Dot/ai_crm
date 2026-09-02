<?php

namespace App\Support\Logistics;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Support\Ai\AiDecision;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ТЗ раздел 9/12 — "отслеживание отправления через AI-чат". Runs in
 * AiWorkflow's chat-assistant chain after every other module's own
 * assistant, same chaining reasoning every other assistant's own docblock
 * already gives.
 *
 * Structurally the odd one out among all six chat assistants shipped this
 * session: there is no multi-turn offer/disambiguation state at all (see
 * LogisticsChatContext's own docblock -- a tracking number IS the whole
 * lookup key, nothing to list or pick from), so this class persists no
 * `flow` in Message.meta and has no reofferForPendingFlow()/continueFlow()
 * pair the way every other assistant does. That also means it never
 * unconditionally overrides $decision on a fallback path -- when nothing
 * actionable is found, it simply returns $decision unchanged -- so unlike
 * every other assistant added this round, it doesn't need an
 * alreadyClaimedByAnotherModule() guard: there is no code path here that
 * could ever clobber an earlier assistant's real reply in the first
 * place. (See feedback_chat_assistant_flow_naming for why every OTHER
 * assistant needs that guard.)
 */
class LogisticsChatAssistant
{
    private const STATUS_LABELS = [
        Shipment::STATUS_RECEIVED => 'Принято',
        Shipment::STATUS_IN_TRANSIT => 'В пути',
        Shipment::STATUS_OUT_FOR_DELIVERY => 'На доставке',
        Shipment::STATUS_DELIVERED => 'Доставлено',
        Shipment::STATUS_RETURNED => 'Возврат',
        Shipment::STATUS_CANCELLED => 'Отменено',
    ];

    public function __construct(
        private readonly LogisticsChatContext $context,
        private readonly LogisticsIntentExtractor $extractor,
        private readonly ShipmentService $shipments,
    ) {
    }

    public function maybeHandle(Tenant $tenant, Company $company, Conversation $conversation, Message $message, AiDecision $decision): AiDecision
    {
        if (! $conversation->customer_id || ! $this->context->isAvailableFor($company)) {
            return $decision;
        }

        try {
            return $this->handle($company, $conversation, $message, $decision, $tenant);
        } catch (Throwable $error) {
            Log::warning('LogisticsChatAssistant failed, falling back to the original reply', [
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'error' => $error->getMessage(),
            ]);

            return $decision;
        }
    }

    private function handle(Company $company, Conversation $conversation, Message $message, AiDecision $decision, Tenant $tenant): AiDecision
    {
        $intent = $this->extractor->extract($tenant, $conversation, $message);

        if ($intent === null || ! $intent['tracking_number'] || (! $intent['wants_track'] && ! $intent['wants_cancel'])) {
            // Either extraction failed, or the customer hasn't named a
            // tracking number yet, or this message isn't about tracking at
            // all -- let the main reply stand either way.
            // LogisticsChatContext::promptSection() already tells the model
            // to ask for the tracking number when it's missing.
            return $decision;
        }

        if ($intent['wants_cancel']) {
            return $this->attemptCancel($company, $intent['tracking_number'], $decision);
        }

        return $this->trackingReply($intent['tracking_number'], $decision);
    }

    private function trackingReply(string $trackingNumber, AiDecision $decision): AiDecision
    {
        $shipment = $this->context->findForTracking($trackingNumber);

        if (! $shipment) {
            return $this->withReply($decision, 'logistics_not_found', "Отправление с трек-номером {$trackingNumber} не найдено. Проверьте, пожалуйста, номер ещё раз.");
        }

        $statusLabel = self::STATUS_LABELS[$shipment->status] ?? $shipment->status;
        $extra = [];

        $lastEvent = $shipment->trackingEvents->last();
        if ($lastEvent?->location) {
            $extra[] = 'Местонахождение: '.$lastEvent->location;
        }
        if ($shipment->status !== Shipment::STATUS_DELIVERED && $shipment->estimated_delivery_at) {
            $extra[] = 'Ожидаемая доставка: '.$shipment->estimated_delivery_at->format('d.m.Y');
        }
        if ($shipment->status === Shipment::STATUS_DELIVERED && $shipment->delivered_at) {
            $extra[] = 'Доставлено: '.$shipment->delivered_at->format('d.m.Y');
        }

        $text = "Статус отправления {$trackingNumber}: {$statusLabel}.".($extra ? ' '.implode('. ', $extra).'.' : '');

        return $this->withReply($decision, 'logistics_tracked', $text);
    }

    private function attemptCancel(Company $company, string $trackingNumber, AiDecision $decision): AiDecision
    {
        $shipment = $this->context->findForCancel($company, $trackingNumber);

        if (! $shipment) {
            return $this->withReply($decision, 'logistics_not_found', "Отправление с трек-номером {$trackingNumber} не найдено в нашей системе. Проверьте, пожалуйста, номер ещё раз.");
        }

        try {
            $this->shipments->updateStatus($shipment, Shipment::STATUS_CANCELLED, null, null, 'Отменено клиентом через AI-чат');
        } catch (ShipmentException $error) {
            return $this->withReply($decision, 'logistics_cancel_conflict', $error->getMessage(), handoff: true);
        }

        return $this->withReply($decision, 'logistics_cancelled', "Хорошо, отменил(а) отправление {$trackingNumber}.");
    }

    private function withReply(AiDecision $decision, string $intent, string $text, bool $handoff = false): AiDecision
    {
        return new AiDecision(
            confidence: $decision->confidence,
            intent: $intent,
            summary: $text,
            nextAction: $handoff ? 'handoff_operator' : 'logistics_flow',
            handoffRequired: $handoff,
            replyText: $text,
            meta: null,
        );
    }
}
