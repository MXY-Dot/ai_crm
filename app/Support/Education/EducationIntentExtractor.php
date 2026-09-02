<?php

namespace App\Support\Education;

use App\Models\Conversation;
use App\Models\Course;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Ai\DifyClient;
use App\Support\Ai\LlmClient;
use App\Support\Integrations\PlatformSettings;
use Illuminate\Support\Collection;

/**
 * A dedicated, single-purpose LLM call mirroring
 * App\Support\Booking\BookingIntentExtractor's own shape (same primary→
 * backup provider pattern, same JSON-only contract, same "match a real
 * catalog name, never invent one" discipline for `course_name`). No
 * `preferred_date`/reschedule fields at all -- an enrollment has no
 * per-customer scheduled date to move, the group's own fixed weekly
 * schedule is the only thing that exists. Only ever invoked when
 * EducationChatContext::isAvailableFor() is true.
 */
class EducationIntentExtractor
{
    private const MAX_RESPONSE_TOKENS = 300;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly PlatformSettings $platform,
        private readonly DifyClient $dify,
    ) {
    }

    /**
     * @param Collection<int, Course> $courses
     * @param array<int, array{group_id:int, course_name:string, employee_name:string, schedule_text:string}> $offeredGroups
     * @param Collection<int, \App\Models\Enrollment> $activeEnrollments
     * @return array{wants_enroll:bool, wants_cancel:bool, course_name:?string, selected_group_index:?int, selected_enrollment_index:?int, cancel_reason:?string}|null
     */
    public function extract(Tenant $tenant, Conversation $conversation, Message $message, Collection $courses, array $offeredGroups, Collection $activeEnrollments): ?array
    {
        $system = $this->systemPrompt($courses, $offeredGroups, $activeEnrollments);
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
            'wants_enroll' => filter_var($data['wants_enroll'] ?? false, FILTER_VALIDATE_BOOL),
            'wants_cancel' => filter_var($data['wants_cancel'] ?? false, FILTER_VALIDATE_BOOL),
            'course_name' => is_string($data['course_name'] ?? null) && trim($data['course_name']) !== '' ? trim($data['course_name']) : null,
            'selected_group_index' => is_numeric($data['selected_group_index'] ?? null) ? (int) $data['selected_group_index'] : null,
            'selected_enrollment_index' => is_numeric($data['selected_enrollment_index'] ?? null) ? (int) $data['selected_enrollment_index'] : null,
            'cancel_reason' => is_string($data['cancel_reason'] ?? null) && trim($data['cancel_reason']) !== '' ? trim($data['cancel_reason']) : null,
        ];
    }

    /**
     * @param Collection<int, Course> $courses
     * @param array<int, array{group_id:int, course_name:string, employee_name:string, schedule_text:string}> $offeredGroups
     * @param Collection<int, \App\Models\Enrollment> $activeEnrollments
     */
    private function systemPrompt(Collection $courses, array $offeredGroups, Collection $activeEnrollments): string
    {
        $courseNames = $courses->pluck('name')->implode(', ');

        $offeredText = $offeredGroups === []
            ? 'Клиенту ничего не предлагалось.'
            : collect($offeredGroups)->map(fn (array $g, int $i): string => $i.': '.$g['course_name'].', '.$g['schedule_text'].' ('.$g['employee_name'].')')->implode("\n");

        $enrollmentsText = $activeEnrollments->isEmpty()
            ? 'У клиента нет активных записей на курсы.'
            : $activeEnrollments->values()->map(function ($enrollment, int $i): string {
                $group = $enrollment->courseGroup;

                return $i.': '.($group?->course?->name ?? 'курс').' ('.($group?->employee?->name ?? '').')';
            })->implode("\n");

        return <<<PROMPT
Ты определяешь намерение клиента насчёт записи на курс в учебном центре, читая последнее сообщение в переписке. Доступные курсы: {$courseNames}.

Ранее клиенту могли быть предложены конкретные группы для записи:
{$offeredText}

Активные записи клиента на курсы (используются, если клиент хочет отменить и нужно понять, какую именно, при нескольких записях):
{$enrollmentsText}

Верни СТРОГО валидный JSON без пояснений и markdown-обрамления:
{
  "wants_enroll": true если клиент хочет ЗАПИСАТЬСЯ на курс (новая запись) или подтверждает предложенный вариант группы, иначе false,
  "wants_cancel": true если клиент хочет ОТМЕНИТЬ существующую запись на курс, иначе false,
  "course_name": ТОЧНОЕ название курса строго из списка выше, если понятно какой нужен для новой записи, иначе null (не выдумывай курс, которого нет в списке),
  "selected_group_index": число -- индекс группы из списка выше, который клиент только что выбрал, или null,
  "selected_enrollment_index": число -- индекс записи из списка АКТИВНЫХ записей выше, если у клиента их несколько и понятно какую он имеет в виду, иначе null,
  "cancel_reason": короткая причина отмены, если клиент её назвал, иначе null
}

Только одно из wants_enroll/wants_cancel может быть true одновременно.
PROMPT;
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
