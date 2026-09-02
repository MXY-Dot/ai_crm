<?php

namespace App\Support\AutoService;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Ai\DifyClient;
use App\Support\Ai\LlmClient;
use App\Support\Integrations\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A dedicated, single-purpose LLM call mirroring every other module's own
 * IntentExtractor shape (same primary→backup provider pattern, same
 * JSON-only response contract), reshaped for repair intake's own fields:
 * no time slot to pick (no `selected_offer_index`), but a VEHICLE to
 * identify instead -- something none of the reservation-shaped modules
 * needed, since a repair job belongs to a specific car, not a generic
 * resource. `wants_status` is new too (not present in Table's/Room's own
 * extractors): "когда будет готова машина?" is the single most natural
 * thing a customer asks here, and surfacing the REAL stored status/
 * diagnosis is exactly the same "never let the model guess" discipline
 * AiChatBookingAssistant's own wants_cancellation_reason established.
 * Only ever invoked when RepairOrderChatContext::isAvailableFor() is true.
 */
class RepairOrderIntentExtractor
{
    private const MAX_RESPONSE_TOKENS = 350;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly PlatformSettings $platform,
        private readonly DifyClient $dify,
    ) {
    }

    /**
     * @param Collection<int, \App\Models\Vehicle> $vehicles
     * @param Collection<int, \App\Models\RepairOrder> $activeOrders
     * @return array{wants_new_order:bool, wants_status:bool, wants_cancel:bool, vehicle_make:?string, vehicle_model:?string, vehicle_plate:?string, problem_description:?string, selected_vehicle_index:?int, selected_order_index:?int, cancel_reason:?string}|null
     */
    public function extract(Tenant $tenant, Conversation $conversation, Message $message, Collection $vehicles, Collection $activeOrders): ?array
    {
        $system = $this->systemPrompt($vehicles, $activeOrders);
        $user = "Последние сообщения переписки:\n".$this->dify->recentMessages($conversation)
            ."\n\nПоследнее сообщение клиента:\n".$message->body;

        $provider = $this->platform->primaryLlmProvider();
        $model = $this->platform->defaultModel();
        $result = $this->llm->complete($tenant, $provider, $model, $system, $user, self::MAX_RESPONSE_TOKENS);

        if ($result === null) {
            $backupProvider = $this->platform->backupLlmProvider();
            if ($backupProvider) {
                $backupModel = $this->platform->defaultModelFor($backupProvider);
                $result = $this->llm->complete($tenant, $backupProvider, $backupModel, $system, $user, self::MAX_RESPONSE_TOKENS);
            }
        }

        if ($result === null) {
            return null;
        }

        $data = $this->parseJson($result['text']);

        if ($data === null) {
            return null;
        }

        return [
            'wants_new_order' => filter_var($data['wants_new_order'] ?? false, FILTER_VALIDATE_BOOL),
            'wants_status' => filter_var($data['wants_status'] ?? false, FILTER_VALIDATE_BOOL),
            'wants_cancel' => filter_var($data['wants_cancel'] ?? false, FILTER_VALIDATE_BOOL),
            'vehicle_make' => is_string($data['vehicle_make'] ?? null) && trim($data['vehicle_make']) !== '' ? trim($data['vehicle_make']) : null,
            'vehicle_model' => is_string($data['vehicle_model'] ?? null) && trim($data['vehicle_model']) !== '' ? trim($data['vehicle_model']) : null,
            'vehicle_plate' => is_string($data['vehicle_plate'] ?? null) && trim($data['vehicle_plate']) !== '' ? trim($data['vehicle_plate']) : null,
            'problem_description' => is_string($data['problem_description'] ?? null) && trim($data['problem_description']) !== '' ? trim($data['problem_description']) : null,
            'selected_vehicle_index' => is_numeric($data['selected_vehicle_index'] ?? null) ? (int) $data['selected_vehicle_index'] : null,
            'selected_order_index' => is_numeric($data['selected_order_index'] ?? null) ? (int) $data['selected_order_index'] : null,
            'cancel_reason' => is_string($data['cancel_reason'] ?? null) && trim($data['cancel_reason']) !== '' ? trim($data['cancel_reason']) : null,
        ];
    }

    /** @param Collection<int, \App\Models\Vehicle> $vehicles @param Collection<int, \App\Models\RepairOrder> $activeOrders */
    private function systemPrompt(Collection $vehicles, Collection $activeOrders): string
    {
        $vehiclesText = $vehicles->isEmpty()
            ? 'У клиента нет зарегистрированных автомобилей.'
            : $vehicles->values()->map(fn ($v, int $i): string => $i.': '.$v->make.' '.$v->model.', гос. номер '.$v->plate_number)->implode("\n");

        $ordersText = $activeOrders->isEmpty()
            ? 'У клиента нет активных заказ-нарядов на ремонт.'
            : $activeOrders->values()->map(function ($order, int $i): string {
                $vehicle = $order->vehicle;

                return $i.': '.($vehicle ? $vehicle->make.' '.$vehicle->model.' ('.$vehicle->plate_number.')' : 'автомобиль').' — '.$order->problem_description;
            })->implode("\n");

        return <<<PROMPT
Ты определяешь намерение клиента насчёт ремонта автомобиля в автосервисе, читая последнее сообщение в переписке.

Автомобили клиента, уже зарегистрированные в системе:
{$vehiclesText}

Активные заказ-наряды клиента (используются, если клиент спрашивает о статусе ремонта или хочет отменить заказ, при нескольких заказах нужно понять, какой именно):
{$ordersText}

Верни СТРОГО валидный JSON без пояснений и markdown-обрамления:
{
  "wants_new_order": true если клиент хочет ЗАПИСАТЬ машину на ремонт/диагностику/обслуживание (новый заказ-наряд), иначе false,
  "wants_status": true если клиент спрашивает о статусе/готовности своего ремонта ("когда будет готово?", "что с моей машиной?"), иначе false,
  "wants_cancel": true если клиент хочет ОТМЕНИТЬ активный заказ-наряд, иначе false,
  "vehicle_make": марка автомобиля, если клиент её назвал для НОВОГО заказа (например "Toyota"), иначе null,
  "vehicle_model": модель автомобиля, если клиент её назвал для НОВОГО заказа (например "Camry"), иначе null,
  "vehicle_plate": гос. номер автомобиля, если клиент его назвал для НОВОГО заказа, иначе null,
  "problem_description": краткое описание проблемы/просьбы клиента для НОВОГО заказа (например "стучит подвеска спереди"), иначе null,
  "selected_vehicle_index": число -- индекс автомобиля из списка ЗАРЕГИСТРИРОВАННЫХ автомобилей выше, если клиент уточняет, о какой из НЕСКОЛЬКИХ своих машин речь, иначе null,
  "selected_order_index": число -- индекс заказа из списка АКТИВНЫХ заказ-нарядов выше, если у клиента их несколько и понятно, какой именно он имеет в виду (для статуса или отмены), иначе null,
  "cancel_reason": короткая причина отмены, если клиент её назвал, иначе null
}

Только одно из wants_new_order/wants_status/wants_cancel может быть true одновременно. Сегодняшняя дата: {$this->today()}.
PROMPT;
    }

    private function today(): string
    {
        return Carbon::now()->format('Y-m-d (l)');
    }

    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (! preg_match('/\{.*\}/s', $text, $matches)) {
            return null;
        }

        $decoded = json_decode($matches[0], true);

        return is_array($decoded) ? $decoded : null;
    }
}
