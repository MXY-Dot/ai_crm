<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\CrmTask;
use App\Models\Tenant;
use App\Support\Ai\DifyClient;
use App\Support\Ai\LlmClient;
use App\Support\Audit\AuditLogger;
use App\Support\Campaigns\CampaignAudience;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * ЭТАП 18 — Marketing Campaigns. WERO never sends anything itself: no
 * consent/opt-in tracking exists anywhere in this schema, and WhatsApp/
 * Telegram both restrict unsolicited bulk messaging — the same constraint
 * already documented on FollowUpAbandonedConversationsCommand (Stage 13) and
 * PostServiceFollowUpCommand (Stage 17). This controller prepares the
 * audience list and offer text for a human to send from their own account;
 * markSent() only records that they did, after the fact.
 */
class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignAudience $audience,
        private readonly DifyClient $dify,
        private readonly LlmClient $llm,
    ) {
    }

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Campaign::class);

        $campaigns = Campaign::query()->latest()->get();
        $campaigns->each(fn (Campaign $campaign) => $campaign->setAttribute('audience_count', $this->audience->query($campaign)->count()));

        return response()->json($campaigns);
    }

    public function show(Campaign $campaign): JsonResponse
    {
        Gate::authorize('view', $campaign);
        $campaign->setAttribute('audience_count', $this->audience->query($campaign)->count());

        return response()->json($campaign);
    }

    public function store(Request $request, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', Campaign::class);

        $tenant = Tenant::query()->findOrFail($context->id());
        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'offer_text' => ['required', 'string', 'max:2000'],
            'segment' => ['nullable', Rule::in(['new', 'returning', 'vip', 'top_vip', 'lost', 'b2b'])],
            'min_purchases' => ['nullable', 'integer', 'min:0'],
            'inactive_days' => ['nullable', 'integer', 'min:1'],
        ]);

        $campaign = Campaign::query()->create($data + [
            'company_id' => $company->id,
            'status' => 'draft',
            'created_by' => $request->user()?->id,
        ]);

        $audit->record('campaign.created', $campaign, $campaign->only(['name', 'segment', 'min_purchases', 'inactive_days']), [], $request);

        return response()->json($campaign, 201);
    }

    /** ЭТАП 18.1's "передать список клиентов" instead of an autonomous send. */
    public function audience(Campaign $campaign): JsonResponse
    {
        Gate::authorize('view', $campaign);

        $customers = $this->audience->query($campaign)->get(['id', 'name', 'phone', 'segment']);

        return response()->json(['data' => $customers, 'count' => $customers->count()]);
    }

    /**
     * ЭТАП 18.1 — "AI помогает подготовить текст". Never saved directly — the
     * operator reviews/edits the suggestion before it's stored via store().
     * Returns an empty string (not an error) whenever no usable model is
     * configured, so a missing/misconfigured AI setup never blocks writing a
     * campaign by hand.
     */
    public function draftCopy(Request $request, TenantContext $context): JsonResponse
    {
        Gate::authorize('create', Campaign::class);

        $tenant = Tenant::query()->findOrFail($context->id());
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'segment' => ['nullable', 'string', 'max:40'],
        ]);

        $agent = AiAgent::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->whereNotNull('model')
            ->first();

        if (! $agent) {
            return response()->json(['text' => '']);
        }

        $provider = $this->llm->providerForModel($agent->model);

        if (! $provider) {
            return response()->json(['text' => '']);
        }

        $systemPrompt = 'You write short, warm marketing offer messages in Russian for a WhatsApp/Telegram broadcast to existing customers, based on the company profile below. Return ONLY the message text — no labels, no quotes, no explanation.';
        $userPrompt = implode("\n\n", array_filter([
            'Business profile:'."\n".$this->dify->businessProfile($agent),
            'Campaign name: '.$data['name'],
            isset($data['segment']) ? 'Target audience segment: '.$data['segment'] : '',
            'Write a short (2-4 sentences) promotional offer message.',
        ]));

        $completion = $this->llm->complete($tenant, $provider, $agent->model, $systemPrompt, $userPrompt);

        return response()->json(['text' => $completion['text'] ?? '']);
    }

    /** ЭТАП 18.4 — Campaign Approval, reusing the existing CrmTask primitive instead of a dedicated approval-engine table. */
    public function submitForApproval(Campaign $campaign, AuditLogger $audit, Request $request): JsonResponse
    {
        Gate::authorize('update', $campaign);
        abort_unless($campaign->status === 'draft', 422, 'Только черновик можно отправить на согласование.');

        $campaign->forceFill(['status' => 'pending_approval'])->save();

        CrmTask::query()->firstOrCreate(
            ['tenant_id' => $campaign->tenant_id, 'company_id' => $campaign->company_id, 'title' => 'Кампания на согласование: '.$campaign->name],
            ['status' => 'open', 'priority' => 'normal', 'description' => 'Проверьте аудиторию и текст кампании «'.$campaign->name.'» и согласуйте запуск.']
        );

        $audit->record('campaign.submitted_for_approval', $campaign, ['status' => 'pending_approval'], ['status' => 'draft'], $request);

        return response()->json($campaign->fresh());
    }

    public function approve(Campaign $campaign, AuditLogger $audit, Request $request): JsonResponse
    {
        Gate::authorize('update', $campaign);
        abort_unless($campaign->status === 'pending_approval', 422, 'Кампания не ожидает согласования.');

        $campaign->forceFill(['status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now()])->save();

        CrmTask::query()
            ->where('title', 'Кампания на согласование: '.$campaign->name)
            ->update(['status' => 'done']);

        $audit->record('campaign.approved', $campaign, ['status' => 'approved'], ['status' => 'pending_approval'], $request);

        return response()->json($campaign->fresh());
    }

    /** The operator sends the campaign themselves (see class docblock) and marks it done here — this never triggers a send. */
    public function markSent(Campaign $campaign, AuditLogger $audit, Request $request): JsonResponse
    {
        Gate::authorize('update', $campaign);
        abort_unless($campaign->status === 'approved', 422, 'Кампания ещё не согласована.');

        $campaign->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        $audit->record('campaign.sent', $campaign, ['status' => 'sent'], ['status' => 'approved'], $request);

        return response()->json($campaign->fresh());
    }
}
