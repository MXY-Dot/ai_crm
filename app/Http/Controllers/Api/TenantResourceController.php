<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

abstract class TenantResourceController extends Controller
{
    abstract protected function model(): string;

    abstract protected function rules(string $action): array;

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', $this->model());

        return response()->json($this->model()::query()->latest()->paginate(20));
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', $this->model());

        $record = $this->model()::query()->create($request->validate($this->rules('store')));
        $audit->record($this->auditAction('created'), $record, $record->toArray(), [], $request);

        return response()->json($record, 201);
    }

    public function show(string $id): JsonResponse
    {
        $record = $this->model()::query()->findOrFail($id);
        Gate::authorize('view', $record);

        return response()->json($record);
    }

    public function update(Request $request, string $id, AuditLogger $audit): JsonResponse
    {
        $record = $this->model()::query()->findOrFail($id);
        Gate::authorize('update', $record);

        $before = $record->toArray();
        $record->update($request->validate($this->rules('update')));
        $audit->record($this->auditAction('updated'), $record, $record->refresh()->toArray(), $before, $request);

        return response()->json($record);
    }

    public function destroy(Request $request, string $id, AuditLogger $audit): JsonResponse
    {
        $record = $this->model()::query()->findOrFail($id);
        Gate::authorize('delete', $record);

        $before = $record->toArray();
        $record->delete();
        $audit->record($this->auditAction('deleted'), $record, [], $before, $request);

        return response()->json(['deleted' => true]);
    }

    /**
     * ЭТАП 10.4 — was entirely unaudited before: no subclass here overrides
     * store/update/destroy (verified — no `parent::` calls anywhere in this
     * codebase), so this is the only place CRUD on Task/Customer/Lead/
     * LanguageExample/Company/KnowledgeDocument gets logged.
     */
    private function auditAction(string $verb): string
    {
        return Str::snake(class_basename($this->model())).'.'.$verb;
    }
}