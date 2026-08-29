<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Models\EmployeeTimeOff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EmployeeController extends TenantResourceController
{
    protected function model(): string
    {
        return Employee::class;
    }

    protected function rules(string $action): array
    {
        $required = $action === 'store' ? 'required' : 'sometimes';

        return [
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'name' => [$required, 'string', 'max:180'],
            'position' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function show(string $id): JsonResponse
    {
        $employee = Employee::query()->with(['schedules', 'timeOff', 'services:id,name'])->findOrFail($id);
        Gate::authorize('view', $employee);

        return response()->json($employee);
    }

    public function updateSchedule(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('update', $employee);

        $data = $request->validate([
            'schedule' => ['present', 'array', 'max:7'],
            'schedule.*.weekday' => ['required', 'integer', 'min:0', 'max:6', 'distinct'],
            'schedule.*.start_time' => ['required', 'date_format:H:i'],
            'schedule.*.end_time' => ['required', 'date_format:H:i', 'after:schedule.*.start_time'],
        ]);

        $employee->schedules()->delete();
        foreach ($data['schedule'] as $row) {
            $employee->schedules()->create($row);
        }

        return response()->json($employee->load('schedules'));
    }

    public function updateServices(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('update', $employee);

        $data = $request->validate([
            'service_ids' => ['present', 'array'],
            'service_ids.*' => ['integer', 'exists:services,id'],
        ]);

        $employee->services()->sync($data['service_ids']);

        return response()->json($employee->load('services:id,name'));
    }

    public function storeTimeOff(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('update', $employee);

        $data = $request->validate([
            'type' => ['required', Rule::in(EmployeeTimeOff::TYPES)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $timeOff = $employee->timeOff()->create($data);

        return response()->json($timeOff, 201);
    }

    public function destroyTimeOff(Employee $employee, EmployeeTimeOff $timeOff): JsonResponse
    {
        Gate::authorize('update', $employee);
        abort_unless($timeOff->employee_id === $employee->id, 404);

        $timeOff->delete();

        return response()->json(['deleted' => true]);
    }
}
