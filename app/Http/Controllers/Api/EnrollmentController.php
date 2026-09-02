<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Enrollment;
use App\Support\Audit\AuditLogger;
use App\Support\Education\EducationConflictException;
use App\Support\Education\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EnrollmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Enrollment::class);

        $data = $request->validate(['course_group_id' => ['nullable', 'integer']]);

        $enrollments = Enrollment::query()
            ->with(['customer:id,name,phone', 'courseGroup:id,name,course_id'])
            ->when($data['course_group_id'] ?? null, fn ($q, $groupId) => $q->where('course_group_id', $groupId))
            ->latest()
            ->paginate(50);

        return response()->json($enrollments);
    }

    public function show(Enrollment $enrollment): JsonResponse
    {
        Gate::authorize('view', $enrollment);

        return response()->json($enrollment->load([
            'customer:id,name,phone,email',
            'courseGroup:id,name,course_id',
            'statusHistory.changedBy:id,name',
            'orders:id,enrollment_id,status,payment_status,total',
        ]));
    }

    public function store(Request $request, EnrollmentService $enrollments, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', Enrollment::class);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'course_group_id' => ['required', 'integer', 'exists:course_groups,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:180'],
            'customer_phone' => ['required_without:customer_id', 'nullable', 'string', 'max:40'],
        ]);

        $customerId = $data['customer_id'] ?? null;
        if (! $customerId) {
            $customer = Customer::query()
                ->where('company_id', $data['company_id'])
                ->where('phone', $data['customer_phone'])
                ->first();

            $customer ??= Customer::create([
                'company_id' => $data['company_id'],
                'name' => $data['customer_name'],
                'phone' => $data['customer_phone'],
                'source' => 'education',
            ]);

            $customerId = $customer->id;
        }

        try {
            $enrollment = $enrollments->enroll([...$data, 'customer_id' => $customerId], $request->user());
        } catch (EducationConflictException $e) {
            throw ValidationException::withMessages(['course_group_id' => $e->getMessage()]);
        }

        $audit->record('enrollment.created', $enrollment, $enrollment->toArray(), [], $request);

        return response()->json($enrollment->load('customer:id,name,phone'), 201);
    }

    public function complete(Request $request, Enrollment $enrollment, EnrollmentService $enrollments, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $enrollment);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);
        $before = $enrollment->toArray();

        try {
            $enrollment = $enrollments->updateStatus($enrollment, Enrollment::STATUS_COMPLETED, $request->user(), $data['comment'] ?? null);
        } catch (EducationConflictException $e) {
            throw ValidationException::withMessages(['enrollment' => $e->getMessage()]);
        }

        $audit->record('enrollment.completed', $enrollment, $enrollment->toArray(), $before, $request);

        return response()->json($enrollment);
    }

    public function cancel(Request $request, Enrollment $enrollment, EnrollmentService $enrollments, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $enrollment);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $before = $enrollment->toArray();

        try {
            $enrollment = $enrollments->cancel($enrollment, $request->user(), $data['reason']);
        } catch (EducationConflictException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        $audit->record('enrollment.cancelled', $enrollment, $enrollment->toArray(), $before, $request);

        return response()->json($enrollment);
    }
}
