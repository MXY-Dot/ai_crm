<?php

namespace App\Support\AutoService;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\RepairOrder;
use App\Models\Tenant;
use App\Models\Vehicle;
use App\Support\Ai\AiDecision;
use App\Support\Chat\ChatButtons;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ТЗ раздел 9/12 — "запись на ремонт через AI-чат". Called from
 * AiWorkflow::process() LAST in the chat-assistant chain (after Booking/
 * Table/Room), same chaining reasoning every other assistant's own
 * docblock already gives: whichever module a tenant actually has enabled
 * is normally the only one whose isAvailableFor() returns true in
 * practice, and each one no-ops instantly for every tenant it doesn't
 * apply to. Running last means nothing after it can clobber a genuine
 * repair-intake reply -- see alreadyClaimedByAnotherModule()'s own
 * docblock for why this class still checks the other three's sentinels
 * anyway (future-proofing against a chain-order change, same reasoning
 * AiChatBookingAssistant's own guard already documents).
 *
 * Structurally the odd one out among the four chat assistants: there is
 * no time slot or resource to offer (see RepairOrderChatContext's own
 * docblock) -- a repair request is created directly once a vehicle and a
 * problem description are known, not picked from a list of options. In
 * exchange, this is the only assistant that has to identify WHICH of the
 * customer's own vehicles a message is about, and the only one with a
 * `wants_status` intent (checking real progress instead of letting the
 * model guess, same discipline as AiChatBookingAssistant's own
 * wants_cancellation_reason).
 */
class RepairOrderChatAssistant
{
    private const STATUS_LABELS = [
        RepairOrder::STATUS_RECEIVED => 'Принят',
        RepairOrder::STATUS_DIAGNOSING => 'Диагностика',
        RepairOrder::STATUS_AWAITING_APPROVAL => 'Ожидает согласования',
        RepairOrder::STATUS_IN_PROGRESS => 'В работе',
        RepairOrder::STATUS_AWAITING_PARTS => 'Ожидает запчасти',
        RepairOrder::STATUS_READY_FOR_PICKUP => 'Готов к выдаче',
        RepairOrder::STATUS_COMPLETED => 'Завершён',
        RepairOrder::STATUS_CANCELLED => 'Отменён',
    ];

    public function __construct(
        private readonly RepairOrderChatContext $context,
        private readonly RepairOrderIntentExtractor $extractor,
        private readonly RepairOrderService $repairOrders,
    ) {
    }

    public function maybeHandle(Tenant $tenant, Company $company, Conversation $conversation, Message $message, AiDecision $decision): AiDecision
    {
        if (! $conversation->customer_id || ! $this->context->isAvailableFor($company)) {
            return $decision;
        }

        try {
            return $this->handle($tenant, $company, $conversation, $message, $decision);
        } catch (Throwable $error) {
            Log::warning('RepairOrderChatAssistant failed, falling back to the original reply', [
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'error' => $error->getMessage(),
            ]);

            // See AiChatBookingAssistant::maybeHandle()'s own docblock (found
            // live via a Groq 429 mid-extraction) for why "the original
            // reply" is unsafe here when a real disambiguation was pending --
            // it can fabricate a confirmation with no order ever created.
            return $this->reofferForPendingFlow($this->lastAiMeta($conversation), $decision) ?? $decision;
        }
    }

    private function handle(Tenant $tenant, Company $company, Conversation $conversation, Message $message, AiDecision $decision): AiDecision
    {
        $vehicles = $this->vehiclesFor($tenant, $conversation);
        $activeOrders = $this->activeOrdersFor($tenant, $conversation);
        $lastMeta = $this->lastAiMeta($conversation);

        $intent = $this->extractor->extract($tenant, $conversation, $message, $vehicles, $activeOrders);

        if ($intent !== null) {
            $continued = $this->continueFlow($tenant, $company, $conversation, $lastMeta, $intent, $decision);

            if ($continued !== null) {
                return $continued;
            }

            if ($intent['wants_cancel']) {
                return $this->startCancel($activeOrders, $intent, $decision);
            }

            if ($intent['wants_status']) {
                return $this->startStatusCheck($activeOrders, $decision);
            }

            if ($intent['wants_new_order']) {
                return $this->startNewOrder($tenant, $company, $conversation, $vehicles, $intent, $decision);
            }
        }

        // Same fix as AiChatBookingAssistant::handle()'s own docblock
        // (feedback_chat_assistant_flow_naming) -- never let this class's own
        // stale-offer safety net override a reply an EARLIER assistant in the
        // chain already produced this turn.
        if ($this->alreadyClaimedByAnotherModule($decision)) {
            return $decision;
        }

        return $this->reofferForPendingFlow($lastMeta, $decision) ?? $decision;
    }

    /** @see AiChatBookingAssistant::handle()'s own docblock on why this guard exists -- listed here in full since this class runs last and every other assistant may genuinely have already claimed the turn. */
    private function alreadyClaimedByAnotherModule(AiDecision $decision): bool
    {
        return in_array($decision->nextAction, ['booking_flow', 'table_reservation_flow', 'room_reservation_flow', 'handoff_operator'], true);
    }

    private function reofferForPendingFlow(array $lastMeta, AiDecision $decision): ?AiDecision
    {
        $flow = $lastMeta['flow'] ?? null;

        if ($flow === 'repair_disambiguate_vehicle') {
            $offered = is_array($lastMeta['offered_vehicles'] ?? null) ? $lastMeta['offered_vehicles'] : [];

            if ($offered === []) {
                return null;
            }

            $lines = collect($offered)->map(fn (array $v, int $i): string => ($i + 1).') '.$v['make'].' '.$v['model'].' — '.$v['plate_number']);

            return $this->withReply($decision, 'repair_reoffer', 'Уточните, пожалуйста, о какой машине речь:'."\n".$lines->implode("\n"), meta: $lastMeta);
        }

        if ($flow === 'repair_disambiguate_order') {
            $offered = is_array($lastMeta['offered_orders'] ?? null) ? $lastMeta['offered_orders'] : [];

            if ($offered === []) {
                return null;
            }

            $lines = collect($offered)->map(fn (array $o, int $i): string => ($i + 1).') '.$o['label']);

            return $this->withReply($decision, 'repair_reoffer', 'Уточните, пожалуйста, какой заказ вы имеете в виду:'."\n".$lines->implode("\n"), meta: $lastMeta);
        }

        return null;
    }

    private function continueFlow(Tenant $tenant, Company $company, Conversation $conversation, array $lastMeta, array $intent, AiDecision $decision): ?AiDecision
    {
        $flow = $lastMeta['flow'] ?? null;

        if ($flow === 'repair_disambiguate_vehicle' && $intent['selected_vehicle_index'] !== null) {
            $offered = is_array($lastMeta['offered_vehicles'] ?? null) ? $lastMeta['offered_vehicles'] : [];

            if (! isset($offered[$intent['selected_vehicle_index']])) {
                return null;
            }

            $vehicle = Vehicle::withoutGlobalScopes()->find($offered[$intent['selected_vehicle_index']]['id']);
            $problemDescription = $lastMeta['problem_description'] ?? null;

            return ($vehicle && $problemDescription) ? $this->attemptCreate($tenant, $company, $conversation, $vehicle, $problemDescription, $decision) : null;
        }

        if ($flow === 'repair_disambiguate_order' && $intent['selected_order_index'] !== null) {
            $offered = is_array($lastMeta['offered_orders'] ?? null) ? $lastMeta['offered_orders'] : [];

            if (! isset($offered[$intent['selected_order_index']])) {
                return null;
            }

            $order = RepairOrder::withoutGlobalScopes()->with(['vehicle:id,make,model,plate_number', 'company:id,timezone'])->find($offered[$intent['selected_order_index']]['id']);

            if (! $order) {
                return null;
            }

            return match ($lastMeta['disambiguate_for'] ?? null) {
                'cancel' => $this->attemptCancel($order, $intent['cancel_reason'], $decision),
                'status' => $this->statusReply($order, $decision),
                default => null,
            };
        }

        return null;
    }

    /** @param Collection<int, RepairOrder> $activeOrders */
    private function startCancel(Collection $activeOrders, array $intent, AiDecision $decision): AiDecision
    {
        if ($activeOrders->isEmpty()) {
            return $this->withReply($decision, 'repair_cancel_none', 'У вас нет активных заказ-нарядов на ремонт для отмены.');
        }

        if ($activeOrders->count() === 1) {
            return $this->attemptCancel($activeOrders->first(), $intent['cancel_reason'], $decision);
        }

        return $this->offerOrdersForDisambiguation($activeOrders, 'cancel', $decision, 'Уточните, пожалуйста, какой заказ отменить:');
    }

    /** @param Collection<int, RepairOrder> $activeOrders */
    private function startStatusCheck(Collection $activeOrders, AiDecision $decision): AiDecision
    {
        if ($activeOrders->isEmpty()) {
            return $this->withReply($decision, 'repair_status_none', 'У вас нет активных заказ-нарядов на ремонт.');
        }

        if ($activeOrders->count() === 1) {
            return $this->statusReply($activeOrders->first(), $decision);
        }

        return $this->offerOrdersForDisambiguation($activeOrders, 'status', $decision, 'Уточните, пожалуйста, о каком заказе речь:');
    }

    /** @param Collection<int, Vehicle> $vehicles */
    private function startNewOrder(Tenant $tenant, Company $company, Conversation $conversation, Collection $vehicles, array $intent, AiDecision $decision): AiDecision
    {
        if (! $intent['problem_description']) {
            // Let the main reply stand -- RepairOrderChatContext::promptSection()
            // already tells the model to ask what's wrong with the car.
            return $decision;
        }

        $vehicle = $this->matchVehicle($vehicles, $intent);

        if ($vehicle) {
            return $this->attemptCreate($tenant, $company, $conversation, $vehicle, $intent['problem_description'], $decision);
        }

        if ($vehicles->count() > 1) {
            return $this->offerVehiclesForDisambiguation($vehicles, $intent['problem_description'], $decision);
        }

        if ($intent['vehicle_make'] && $intent['vehicle_model'] && $intent['vehicle_plate']) {
            $vehicle = Vehicle::create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'customer_id' => $conversation->customer_id,
                'make' => $intent['vehicle_make'],
                'model' => $intent['vehicle_model'],
                'plate_number' => $intent['vehicle_plate'],
            ]);

            return $this->attemptCreate($tenant, $company, $conversation, $vehicle, $intent['problem_description'], $decision);
        }

        // No vehicle on file and not enough info to register a new one yet --
        // let the main reply stand and ask for make/model/plate.
        return $decision;
    }

    /**
     * Picks the customer's own vehicle a message is about. A lone vehicle on
     * file is assumed to be the one meant UNLESS the customer named a
     * different make than that vehicle's own -- a real, if imperfect, signal
     * they mean a second (not-yet-registered) car rather than always
     * defaulting to the only one on file.
     */
    private function matchVehicle(Collection $vehicles, array $intent): ?Vehicle
    {
        if ($vehicles->isEmpty()) {
            return null;
        }

        if ($intent['vehicle_plate']) {
            $normalized = mb_strtolower(str_replace(' ', '', $intent['vehicle_plate']));
            $match = $vehicles->first(fn (Vehicle $v): bool => mb_strtolower(str_replace(' ', '', $v->plate_number)) === $normalized);

            if ($match) {
                return $match;
            }
        }

        if ($intent['vehicle_make'] || $intent['vehicle_model']) {
            $matches = $vehicles->filter(fn (Vehicle $v): bool =>
                (! $intent['vehicle_make'] || mb_strtolower($v->make) === mb_strtolower($intent['vehicle_make']))
                && (! $intent['vehicle_model'] || mb_strtolower($v->model) === mb_strtolower($intent['vehicle_model'])));

            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        if ($vehicles->count() === 1) {
            $only = $vehicles->first();
            $makeMismatch = $intent['vehicle_make'] && mb_strtolower($intent['vehicle_make']) !== mb_strtolower($only->make);

            return $makeMismatch ? null : $only;
        }

        return null;
    }

    /** @param Collection<int, Vehicle> $vehicles */
    private function offerVehiclesForDisambiguation(Collection $vehicles, string $problemDescription, AiDecision $decision): AiDecision
    {
        $offered = $vehicles->values()->map(fn (Vehicle $v): array => [
            'id' => $v->id,
            'make' => $v->make,
            'model' => $v->model,
            'plate_number' => $v->plate_number,
        ])->all();

        $lines = collect($offered)->map(fn (array $v, int $i): string => ($i + 1).') '.$v['make'].' '.$v['model'].' — '.$v['plate_number']);
        $text = 'У вас несколько автомобилей в системе, уточните, пожалуйста, о какой машине речь:'."\n".$lines->implode("\n");
        $rawButtons = RepairOrderDisambiguationButtons::forVehicles($offered);

        return $this->withReply($decision, 'repair_disambiguate', $text, meta: [
            'flow' => 'repair_disambiguate_vehicle',
            'offered_vehicles' => $offered,
            'problem_description' => $problemDescription,
            'raw_buttons' => $rawButtons,
            'buttons' => ChatButtons::forOffer($rawButtons),
        ]);
    }

    /** @param Collection<int, RepairOrder> $activeOrders */
    private function offerOrdersForDisambiguation(Collection $activeOrders, string $for, AiDecision $decision, string $intro): AiDecision
    {
        $offered = $activeOrders->values()->map(fn (RepairOrder $o): array => [
            'id' => $o->id,
            'label' => $this->vehicleLabel($o).' — '.$o->problem_description,
        ])->all();

        $lines = collect($offered)->map(fn (array $o, int $i): string => ($i + 1).') '.$o['label']);
        $text = $intro."\n".$lines->implode("\n");
        $rawButtons = RepairOrderDisambiguationButtons::forOrders($offered);

        return $this->withReply($decision, 'repair_disambiguate', $text, meta: [
            'flow' => 'repair_disambiguate_order',
            'disambiguate_for' => $for,
            'offered_orders' => $offered,
            'raw_buttons' => $rawButtons,
            'buttons' => ChatButtons::forOffer($rawButtons),
        ]);
    }

    private function attemptCreate(Tenant $tenant, Company $company, Conversation $conversation, Vehicle $vehicle, string $problemDescription, AiDecision $decision): AiDecision
    {
        try {
            $this->repairOrders->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'customer_id' => $conversation->customer_id,
                'vehicle_id' => $vehicle->id,
                'problem_description' => $problemDescription,
                'notes' => 'Создано через AI-чат',
            ], null);
        } catch (RepairOrderConflictException $error) {
            return $this->withReply($decision, 'repair_conflict', $error->getMessage().' Чтобы узнать статус текущего заказа, просто спросите об этом здесь.');
        }

        $text = "Готово! Записал(а) «{$vehicle->make} {$vehicle->model}» ({$vehicle->plate_number}) на ремонт: {$problemDescription}. Мастер свяжется с вами для уточнения деталей и стоимости.";

        return $this->withReply($decision, 'repair_created', $text);
    }

    private function attemptCancel(RepairOrder $order, ?string $reason, AiDecision $decision): AiDecision
    {
        try {
            $order = $this->repairOrders->cancel($order, null, $reason ?? 'Отменено клиентом через AI-чат');
        } catch (RepairOrderConflictException $error) {
            return $this->withReply($decision, 'repair_cancel_conflict', $error->getMessage(), handoff: true);
        }

        $text = "Хорошо, отменил(а) заказ-наряд на ремонт «{$this->vehicleLabel($order)}».";

        return $this->withReply($decision, 'repair_cancelled', $text);
    }

    /**
     * Real, stored status/diagnosis/estimate/promised date -- never the
     * model's own guess. Same discipline as
     * AiChatBookingAssistant::explainCancellation()'s own docblock.
     */
    private function statusReply(RepairOrder $order, AiDecision $decision): AiDecision
    {
        $statusLabel = self::STATUS_LABELS[$order->status] ?? $order->status;
        $extra = [];

        if ($order->diagnosis_notes) {
            $extra[] = 'Диагностика: '.$order->diagnosis_notes;
        }
        if ($order->estimated_total) {
            $extra[] = 'Ориентировочная стоимость: '.number_format((float) $order->estimated_total, 0, ',', ' ').' смн';
        }
        if ($order->promised_at) {
            $timezone = $order->company?->timezone ?: config('app.timezone');
            $extra[] = 'Ожидаемая готовность: '.$order->promised_at->copy()->setTimezone($timezone)->format('d.m.Y');
        }

        $text = "Статус ремонта «{$this->vehicleLabel($order)}»: {$statusLabel}.".($extra ? ' '.implode('. ', $extra).'.' : '');

        return $this->withReply($decision, 'repair_status', $text);
    }

    private function vehicleLabel(RepairOrder $order): string
    {
        return $order->vehicle ? $order->vehicle->make.' '.$order->vehicle->model.' ('.$order->vehicle->plate_number.')' : 'автомобиль';
    }

    /** @return Collection<int, Vehicle> */
    private function vehiclesFor(Tenant $tenant, Conversation $conversation): Collection
    {
        return Vehicle::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $conversation->customer_id)
            ->orderBy('id')
            ->limit(10)
            ->get();
    }

    /** @return Collection<int, RepairOrder> */
    private function activeOrdersFor(Tenant $tenant, Conversation $conversation): Collection
    {
        return RepairOrder::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $conversation->customer_id)
            ->whereIn('status', RepairOrder::ACTIVE_STATUSES)
            ->with(['vehicle:id,make,model,plate_number', 'company:id,timezone'])
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    private function lastAiMeta(Conversation $conversation): array
    {
        $lastAiMessage = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', 'ai')
            ->latest('id')
            ->first();

        $meta = $lastAiMessage?->meta;

        return is_array($meta) ? $meta : [];
    }

    private function withReply(AiDecision $decision, string $intent, string $text, bool $handoff = false, ?array $meta = null): AiDecision
    {
        return new AiDecision(
            confidence: $decision->confidence,
            intent: $intent,
            summary: $text,
            nextAction: $handoff ? 'handoff_operator' : 'repair_order_flow',
            handoffRequired: $handoff,
            replyText: $text,
            meta: $meta,
        );
    }
}
