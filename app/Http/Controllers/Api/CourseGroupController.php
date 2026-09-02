<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseGroup;
use App\Models\Enrollment;
use App\Support\Audit\AuditLogger;
use App\Support\Education\CourseGroupService;
use App\Support\Education\EducationConflictException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourseGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CourseGroup::class);

        $data = $request->validate([
            'course_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(CourseGroup::STATUSES)],
        ]);

        $groups = CourseGroup::query()
            ->withCount(['enrollments' => fn ($q) => $q->whereIn('status', Enrollment::ACTIVE_STATUSES)])
            ->with(['course:id,name,price', 'employee:id,name', 'resource:id,name'])
            ->when($data['course_id'] ?? null, fn ($q, $courseId) => $q->where('course_id', $courseId))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(50);

        return response()->json($groups);
    }

    public function show(CourseGroup $courseGroup): JsonResponse
    {
        Gate::authorize('view', $courseGroup);

        return response()->json($courseGroup->load([
            'course:id,name,price,duration_lessons',
            'employee:id,name',
            'resource:id,name',
            'createdBy:id,name',
            'enrollments.customer:id,name,phone',
        ]));
    }

    public function store(Request $request, CourseGroupService $groups, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', CourseGroup::class);

        $data = $this->validated($request);

        try {
            $group = $groups->create($data, $request->user());
        } catch (EducationConflictException $e) {
            throw ValidationException::withMessages(['schedule' => $e->getMessage()]);
        }

        $audit->record('course_group.created', $group, $group->toArray(), [], $request);

        return response()->json($group->load(['course:id,name', 'employee:id,name', 'resource:id,name']), 201);
    }

    public function update(Request $request, CourseGroup $courseGroup, CourseGroupService $groups, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $courseGroup);

        $data = $this->validated($request, 'update');
        $before = $courseGroup->toArray();

        try {
            $courseGroup = $groups->update($courseGroup, $data, $request->user());
        } catch (EducationConflictException $e) {
            throw ValidationException::withMessages(['schedule' => $e->getMessage()]);
        }

        $audit->record('course_group.updated', $courseGroup, $courseGroup->toArray(), $before, $request);

        return response()->json($courseGroup);
    }

    private function validated(Request $request, string $action = 'store'): array
    {
        $required = $action === 'store' ? 'required' : 'sometimes';

        $data = $request->validate([
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'course_id' => [$required, 'integer', 'exists:courses,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'resource_id' => ['nullable', 'integer', 'exists:resources,id'],
            'name' => [$required, 'string', 'max:180'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'schedule' => [$required, 'array', 'min:1'],
            'schedule.*.weekday' => ['required', 'integer', 'min:0', 'max:6'],
            'schedule.*.start_time' => ['required', 'date_format:H:i'],
            'schedule.*.end_time' => ['required', 'date_format:H:i'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(CourseGroup::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        foreach ($data['schedule'] ?? [] as $slot) {
            if ($slot['end_time'] <= $slot['start_time']) {
                throw ValidationException::withMessages(['schedule' => 'Время окончания занятия должно быть позже времени начала.']);
            }
        }

        return $data;
    }
}
