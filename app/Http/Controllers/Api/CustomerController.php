<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Support\Customers\CustomerMatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CustomerController extends TenantResourceController
{
    protected function model(): string
    {
        return Customer::class;
    }

    protected function rules(string $action): array
    {
        $required = $action === 'store' ? 'required' : 'sometimes';

        return [
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'name' => [$required, 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'source' => ['nullable', 'string', 'max:80'],
            'tags' => ['nullable', 'array'],
            'meta' => ['nullable', 'array'],
            'is_business' => ['nullable', 'boolean'],
            'city' => ['nullable', 'string', 'max:120'],
            'birth_year' => ['nullable', 'integer', 'min:1920', 'max:'.(now()->year - 10)],
        ];
    }

    /**
     * ЭТАП 12.1 — customers who already diverged into two rows before identity
     * resolution existed for the website widget (see CustomerMatcher/
     * WidgetController::phone()). Grouped by phone only — the one unambiguous
     * exact-match signal — never auto-merged, just surfaced for a human to
     * confirm via merge() below.
     */
    public function duplicates(): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $phones = Customer::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->select('phone')
            ->groupBy('phone')
            ->havingRaw('count(*) > 1')
            ->pluck('phone');

        $groups = $phones->map(fn (string $phone) => Customer::query()
            ->where('phone', $phone)
            ->orderBy('created_at')
            ->get(['id', 'name', 'phone', 'email', 'source', 'created_at']))
            ->values();

        return response()->json(['data' => $groups]);
    }

    /** Always merges into the oldest record in a duplicate group — deterministic, no picker needed. */
    public function merge(Request $request, TenantContext $context, CustomerMatcher $matcher): JsonResponse
    {
        $data = $request->validate([
            'winner_id' => ['required', 'integer', 'exists:customers,id'],
            'loser_id' => ['required', 'integer', 'exists:customers,id', 'different:winner_id'],
        ]);

        $winner = Customer::withoutGlobalScopes()->where('tenant_id', $context->id())->findOrFail($data['winner_id']);
        $loser = Customer::withoutGlobalScopes()->where('tenant_id', $context->id())->findOrFail($data['loser_id']);

        Gate::authorize('delete', $loser);

        $matcher->mergeInto($winner, $loser);

        return response()->json(['merged' => true, 'winner_id' => $winner->id]);
    }
}
