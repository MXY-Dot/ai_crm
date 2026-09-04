<?php

namespace App\Support\Education;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\Enrollment;
use Illuminate\Support\Collection;

/**
 * ТЗ раздел 9/12 — "запись на курс через AI-чат". Gate + real-data context
 * shared by EducationChatAssistant and (via promptSection())
 * DifyClient::businessProfile(), mirroring BookingChatContext's own shape
 * closely: a Course here plays the same role a Service does for Booking
 * (named catalog offering, matched by exact name from the customer's own
 * words), but the thing actually "offered" is a CourseGroup -- Course is
 * the WHAT, CourseGroup is the WHEN/WHO (schedule + teacher + seats), same
 * relationship AvailabilityCalculator has to Service/Employee. Unlike
 * Booking, there's no day-stepping search: a course's open groups are a
 * short, fixed list (a course rarely runs more than a couple of groups at
 * once), so nextAvailableSlots()-style pagination isn't needed here.
 */
class EducationChatContext
{
    private const WEEKDAY_LABELS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

    /** course_scheduling module enabled AND at least one active course exists -- a toggled-on module with nothing in the catalog yet should not activate this. */
    public function isAvailableFor(Company $company): bool
    {
        $moduleEnabled = CompanyModule::withoutGlobalScopes()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('module_key', 'course_scheduling')
            ->where('enabled', true)
            ->exists();

        if (! $moduleEnabled) {
            return false;
        }

        return $this->activeCourses($company)->isNotEmpty();
    }

    /** @return Collection<int, Course> */
    public function activeCourses(Company $company): Collection
    {
        return Course::withoutGlobalScopes()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

    /** Injected into DifyClient::businessProfile() alongside every other enabled module's own section. Deliberately never mentions a specific group/schedule/seat count itself -- those only ever come from openGroupsForCourse() below. */
    public function promptSection(Company $company): string
    {
        // Found live (same bug as TableReservationChatContext): this only checked
        // activeCourses()->isEmpty(), never the course_scheduling module flag
        // itself. isAvailableFor() already covers both checks.
        if (! $this->isAvailableFor($company)) {
            return '';
        }

        $courses = $this->activeCourses($company);

        $lines = $courses->map(fn (Course $c): string => sprintf('- %s (%s смн)', $c->name, number_format((float) $c->price, 0, ',', ' ')));

        return "Запись на курсы доступна прямо в этом чате. Список курсов (используй ТОЛЬКО эти названия, не выдумывай другие):\n"
            .$lines->implode("\n")
            ."\nЕсли клиент хочет записаться — уточни, на какой курс, если это ещё не ясно. Никогда не называй клиенту расписание группы или свободные места сам — это подбирает отдельная система и предложит следующим сообщением.";
    }

    /**
     * Real open groups (still recruiting/active AND still has a free seat)
     * for $course, each entry self-contained enough to persist in
     * Message.meta and act on next turn without re-resolving anything --
     * same shape convention as every other module's own offered-list.
     *
     * @return array<int, array{group_id:int, course_name:string, employee_name:string, schedule_text:string}>
     */
    public function openGroupsForCourse(Company $company, Course $course): array
    {
        $groups = CourseGroup::withoutGlobalScopes()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('course_id', $course->id)
            ->whereIn('status', CourseGroup::ACTIVE_STATUSES)
            ->withCount(['enrollments as active_enrollments_count' => fn ($q) => $q->whereIn('status', Enrollment::ACTIVE_STATUSES)])
            ->with('employee:id,name')
            ->orderBy('id')
            ->get()
            ->filter(fn (CourseGroup $g): bool => $g->capacity === null || $g->active_enrollments_count < $g->capacity);

        return $groups->map(fn (CourseGroup $g): array => [
            'group_id' => $g->id,
            'course_name' => $course->name,
            'employee_name' => $g->employee?->name ?? '',
            'schedule_text' => $this->scheduleText($g->schedule ?? []),
        ])->values()->all();
    }

    /** @param array<int, array{weekday:int, start_time:string, end_time:string}> $schedule */
    private function scheduleText(array $schedule): string
    {
        return collect($schedule)
            ->map(fn (array $slot): string => (self::WEEKDAY_LABELS[$slot['weekday'] ?? -1] ?? '?').' '.substr($slot['start_time'] ?? '', 0, 5).'–'.substr($slot['end_time'] ?? '', 0, 5))
            ->implode(', ');
    }
}
