<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

abstract class TenantResourceController extends Controller
{
    abstract protected function model(): string;

    abstract protected function rules(string $action): array;

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', $this->model());

        return response()->json($this->model()::query()->latest()->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', $this->model());

        $record = $this->model()::query()->create($request->validate($this->rules('store')));

        return response()->json($record, 201);
    }

    public function show(string $id): JsonResponse
    {
        $record = $this->model()::query()->findOrFail($id);
        Gate::authorize('view', $record);

        return response()->json($record);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $record = $this->model()::query()->findOrFail($id);
        Gate::authorize('update', $record);

        $record->update($request->validate($this->rules('update')));

        return response()->json($record->refresh());
    }

    public function destroy(string $id): JsonResponse
    {
        $record = $this->model()::query()->findOrFail($id);
        Gate::authorize('delete', $record);

        $record->delete();

        return response()->json(['deleted' => true]);
    }
}