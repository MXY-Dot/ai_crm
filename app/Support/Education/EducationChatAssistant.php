<?php

namespace App\Support\Education;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Ai\AiDecision;
use App\Support\Chat\ChatButtons;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ТЗ раздел 9/12 — "запись на курс через AI-чат". Runs in AiWorkflow's
 * chat-assistant chain after Booking/Table/Room/RepairOrder, same
 * chaining reasoning every other assistant's own docblock already gives.
 * Applies both feedback_chat_assistant_flow_naming lessons from the start
 * (unique `education_*` flow prefixes, alreadyClaimedByAnotherModule()
 * guard against every OTHER assistant's own sentinel) rather than
 * retrofitting, same as RepairOrderChatAssistant did.
 *
 * Shape-wise, closest to AiChatBookingAssistant: a named catalog thing
 * (Course, like Service) is matched from the customer's own words, then a
 * real, availability-checked option (a CourseGroup, like an employee time
 * slot) is offered from it -- never invented by the model itself. No
 * reschedule flow at all (an enrollment has no per-customer date to move,
 * see EducationIntentExtractor's own docblock).
 */
class EducationChatAssistant
{
    public function __construct(
        private readonly EducationChatContext $context,
        private readonly EducationIntentExtractor $extractor,
        private readonly EnrollmentService $enrollments,
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
            Log::warning('EducationChatAssistant failed, falling back to the original reply', [
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'error' => $error->getMessage(),
            ]);

            return $decision;
        }
    }

    private function handle(Tenant $tenant, Company $company, Conversation $conversation, Message $message, AiDecision $decision): AiDecision
    {
        $courses = $this->context->activeCourses($company);
        $activeEnrollments = $this->activeEnrollmentsFor($tenant, $conversation);
        $lastMeta = $this->lastAiMeta($conversation);

        // See feedback_chat_assistant_flow_naming -- only trust offered_groups
        // when the last turn's flow is actually one of OUR OWN, uniquely-named
        // values.
        $ownFlow = in_array($lastMeta['flow'] ?? null, ['education_offer_groups'], true);
        $offeredGroups = $ownFlow && is_array($lastMeta['offered_groups'] ?? null) ? $lastMeta['offered_groups'] : [];

        $intent = $this->extractor->extract($tenant, $conversation, $message, $courses, $offeredGroups, $activeEnrollments);

        if ($intent !== null) {
            $continued = $this->continueFlow($tenant, $company, $conversation, $lastMeta, $intent, $decision);

            if ($continued !== null) {
                return $continued;
            }

            if ($intent['wants_cancel']) {
                return $this->startCancel($activeEnrollments, $intent, $decision);
            }

            if ($intent['wants_enroll']) {
                return $this->startEnroll($tenant, $company, $conversation, $courses, $intent, $decision);
            }
        }

        if ($this->alreadyClaimedByAnotherModule($decision)) {
            return $decision;
        }

        return $this->reofferForPendingFlow($lastMeta, $decision) ?? $decision;
    }

    /** @see AiChatBookingAssistant::handle()'s own docblock on why this guard exists. */
    private function alreadyClaimedByAnotherModule(AiDecision $decision): bool
    {
        return in_array($decision->nextAction, ['booking_flow', 'table_reservation_flow', 'room_reservation_flow', 'repair_order_flow', 'handoff_operator'], true);
    }

    private function reofferForPendingFlow(array $lastMeta, AiDecision $decision): ?AiDecision
    {
        $flow = $lastMeta['flow'] ?? null;

        if ($flow === 'education_offer_groups') {
            $offeredGroups = is_array($lastMeta['offered_groups'] ?? null) ? $lastMeta['offered_groups'] : [];

            if ($offeredGroups === []) {
                return null;
            }

            return $this->withReply($decision, 'education_reoffer', 'Уточните, пожалуйста, какая из предложенных групп вам подходит:'."\n".$this->formatOffers($offeredGroups), meta: $lastMeta);
        }

        if ($flow === 'education_disambiguate_enrollment') {
            $offeredEnrollments = is_array($lastMeta['offered_enrollments'] ?? null) ? $lastMeta['offered_enrollments'] : [];

            if ($offeredEnrollments === []) {
                return null;
            }

            $lines = collect($offeredEnrollments)->map(fn (array $e, int $i): string => ($i + 1).') '.$e['label']);

            return $this->withReply($decision, 'education_reoffer', 'Уточните, пожалуйста, какую запись вы имеете в виду:'."\n".$lines->implode("\n"), meta: $lastMeta);
        }

        return null;
    }

    private function continueFlow(Tenant $tenant, Company $company, Conversation $conversation, array $lastMeta, array $intent, AiDecision $decision): ?AiDecision
    {
        $flow = $lastMeta['flow'] ?? null;

        if ($flow === 'education_offer_groups' && $intent['selected_group_index'] !== null) {
            $offered = is_array($lastMeta['offered_groups'] ?? null) ? $lastMeta['offered_groups'] : [];

            return isset($offered[$intent['selected_group_index']])
                ? $this->attemptEnroll($tenant, $company, $conversation, $offered[$intent['selected_group_index']], $decision)
                : null;
        }

        if ($flow === 'education_disambiguate_enrollment' && $intent['selected_enrollment_index'] !== null) {
            $offered = is_array($lastMeta['offered_enrollments'] ?? null) ? $lastMeta['offered_enrollments'] : [];

            if (! isset($offered[$intent['selected_enrollment_index']])) {
                return null;
            }

            $enrollment = Enrollment::withoutGlobalScopes()->with(['courseGroup.course', 'courseGroup.employee'])->find($offered[$intent['selected_enrollment_index']]['id']);

            return $enrollment ? $this->attemptCancel($enrollment, $intent['cancel_reason'], $decision) : null;
        }

        return null;
    }

    /** @param Collection<int, Enrollment> $activeEnrollments */
    private function startCancel(Collection $activeEnrollments, array $intent, AiDecision $decision): AiDecision
    {
        if ($activeEnrollments->isEmpty()) {
            return $this->withReply($decision, 'education_cancel_none', 'У вас нет активных записей на курсы для отмены.');
        }

        if ($activeEnrollments->count() === 1) {
            return $this->attemptCancel($activeEnrollments->first(), $intent['cancel_reason'], $decision);
        }

        $offered = $activeEnrollments->values()->map(fn (Enrollment $e): array => [
            'id' => $e->id,
            'label' => ($e->courseGroup?->course?->name ?? 'курс').' ('.($e->courseGroup?->employee?->name ?? '').')',
        ])->all();

        $lines = collect($offered)->map(fn (array $e, int $i): string => ($i + 1).') '.$e['label']);
        $text = 'Уточните, пожалуйста, какую запись отменить:'."\n".$lines->implode("\n");

        $rawButtons = EducationOfferButtons::forExistingEnrollments($offered);

        return $this->withReply($decision, 'education_disambiguate', $text, meta: [
            'flow' => 'education_disambiguate_enrollment',
            'offered_enrollments' => $offered,
            'raw_buttons' => $rawButtons,
            'buttons' => ChatButtons::forOffer($rawButtons),
        ]);
    }

    /** @param Collection<int, Course> $courses */
    private function startEnroll(Tenant $tenant, Company $company, Conversation $conversation, Collection $courses, array $intent, AiDecision $decision): AiDecision
    {
        $course = $this->resolveCourse($courses, $intent['course_name']);

        if (! $course) {
            // Let the main reply stand -- EducationChatContext::promptSection()
            // already tells the model to ask which course, listing the real ones.
            return $decision;
        }

        $groups = $this->context->openGroupsForCourse($company, $course);

        if ($groups === []) {
            return $this->withReply(
                $decision,
                'education_no_groups',
                "К сожалению, сейчас нет открытых групп с местами на курс «{$course->name}». Оператор подберёт вариант вручную и свяжется с вами.",
                handoff: true,
            );
        }

        if (count($groups) === 1) {
            return $this->attemptEnroll($tenant, $company, $conversation, $groups[0], $decision);
        }

        $text = "Вот открытые группы на курс «{$course->name}»:\n"
            .$this->formatOffers($groups)
            ."\nНапишите номер варианта, который вам подходит, и я вас запишу.";

        $rawButtons = EducationOfferButtons::build($groups);

        return $this->withReply($decision, 'education_offer', $text, meta: [
            'flow' => 'education_offer_groups',
            'offered_groups' => $groups,
            'raw_buttons' => $rawButtons,
            'buttons' => ChatButtons::forOffer($rawButtons),
        ]);
    }

    /** @param array{group_id:int, course_name:string, employee_name:string, schedule_text:string} $group */
    private function attemptEnroll(Tenant $tenant, Company $company, Conversation $conversation, array $group, AiDecision $decision): AiDecision
    {
        try {
            $this->enrollments->enroll([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'course_group_id' => $group['group_id'],
                'customer_id' => $conversation->customer_id,
                'notes' => 'Записан через AI-чат',
            ], null);
        } catch (EducationConflictException $error) {
            return $this->withReply($decision, 'education_conflict', $error->getMessage().' Если хотите, я поищу другую группу — просто напишите об этом.');
        }

        $text = "Готово! Записал(а) вас в группу «{$group['course_name']}» ({$group['schedule_text']}, преподаватель {$group['employee_name']}). Если нужно отменить запись — просто напишите об этом здесь.";

        return $this->withReply($decision, 'education_enrolled', $text);
    }

    private function attemptCancel(Enrollment $enrollment, ?string $reason, AiDecision $decision): AiDecision
    {
        $courseName = $enrollment->courseGroup?->course?->name ?? 'курс';

        try {
            $this->enrollments->cancel($enrollment, null, $reason ?? 'Отменено клиентом через AI-чат');
        } catch (EducationConflictException $error) {
            return $this->withReply($decision, 'education_cancel_conflict', $error->getMessage(), handoff: true);
        }

        $text = "Хорошо, отменил(а) вашу запись на курс «{$courseName}».";

        return $this->withReply($decision, 'education_cancelled', $text);
    }

    /** @param Collection<int, Course> $courses */
    private function resolveCourse(Collection $courses, ?string $name): ?Course
    {
        if (! $name) {
            return null;
        }

        return $courses->first(fn (Course $c): bool => mb_strtolower(trim($c->name)) === mb_strtolower(trim($name)));
    }

    /** @param array<int, array{group_id:int, course_name:string, employee_name:string, schedule_text:string}> $groups */
    private function formatOffers(array $groups): string
    {
        return collect($groups)
            ->map(fn (array $g, int $i): string => ($i + 1).') '.$g['schedule_text'].' — '.$g['employee_name'])
            ->implode("\n");
    }

    /** @return Collection<int, Enrollment> */
    private function activeEnrollmentsFor(Tenant $tenant, Conversation $conversation): Collection
    {
        return Enrollment::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $conversation->customer_id)
            ->whereIn('status', Enrollment::ACTIVE_STATUSES)
            ->with(['courseGroup.course', 'courseGroup.employee'])
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
            nextAction: $handoff ? 'handoff_operator' : 'education_flow',
            handoffRequired: $handoff,
            replyText: $text,
            meta: $meta,
        );
    }
}
