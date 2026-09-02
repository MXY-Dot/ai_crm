<?php

namespace App\Http\Controllers\Api;

use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class VehicleController extends TenantResourceController
{
    protected function model(): string
    {
        return Vehicle::class;
    }

    protected function rules(string $action): array
    {
        $required = $action === 'store' ? 'required' : 'sometimes';

        return [
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'customer_id' => [$required, 'integer', 'exists:customers,id'],
            'make' => [$required, 'string', 'max:80'],
            'model' => [$required, 'string', 'max:80'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'plate_number' => [$required, 'string', 'max:20'],
            'vin' => ['nullable', 'string', 'max:32'],
            'color' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The Автомобили tab needs the owning customer's name/phone alongside each
     * row -- overridden (zero-param signature kept identical to
     * TenantResourceController::index(), see ResourceController's own docblock
     * for why: PHP enforces override compatibility even though Laravel's DI
     * would happily inject a Request here) purely to add eager loading.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Vehicle::class);

        return response()->json(Vehicle::query()->with('customer:id,name,phone')->latest()->paginate(20));
    }
}
