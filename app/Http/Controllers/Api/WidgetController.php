<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAiReplyJob;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Models\WidgetToken;
use App\Support\Ai\LlmClient;
use App\Support\Customers\CustomerMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Public, unauthenticated API for the embeddable website chat widget
 * (public/widget.js) — a visitor is never a logged-in `User`, so unlike every
 * other controller in this namespace there's no TenantContext/Gate here at
 * all. Instead every request carries a `siteKey` — a `WidgetToken.token`, one
 * of possibly several a tenant has created (one per site/page) via
 * WidgetTokenController, each resolving to that tenant's single
 * `provider = 'website'` Channel so conversations/leads always funnel into
 * one place regardless of which token a visitor's page used — which is the
 * sole tenant-resolution mechanism; treat it like a public, non-secret app id
 * (same trust model as Intercom's `app_id` or Crisp's website id: it's
 * visible in page source by design).
 */
class WidgetController extends Controller
{
    public function __construct(private readonly LlmClient $llm, private readonly CustomerMatcher $customers)
    {
    }

    /**
     * Color/position only, no conversation side effects — called immediately on
     * page load (before the visitor has clicked anything) so the bubble itself
     * reflects the tenant's branding from first paint, not just after `/start`
     * (which only fires on first click, and — unlike this — creates a Customer/
     * Lead/Conversation as a side effect, so it can't be called on every page
     * view without spamming empty "ghost" conversations).
     */
    public function appearance(string $siteKey): JsonResponse
    {
        $channel = $this->channel($siteKey);

        return response()->json($this->appearancePayload($channel));
    }

    private function appearancePayload(Channel $channel): array
    {
        $settings = $channel->settings ?? [];

        return [
            'color' => Arr::get($settings, 'widget_color', '#16a34a'),
            'position' => Arr::get($settings, 'widget_position', 'right'),
            'launcher_icon' => Arr::get($settings, 'widget_launcher_icon', 'chat'),
        ];
    }

    /**
     * Who's actually going to answer, right now — not a static setting. If an
     * operator currently has this conversation open (same presence signal
     * AiWorkflow::process() itself checks before deciding to auto-reply — see
     * ConversationTypingController::hasActiveViewer()), show that real person's
     * name/avatar and "online". Otherwise AI is what actually responds, so show
     * that instead — the header always reflects who the visitor is really
     * talking to, not a name the tenant typed in once and forgot about.
     */
    private function agentPayload(Conversation $conversation): array
    {
        $viewerId = ConversationTypingController::activeViewerUserId($conversation->id);
        $operator = $viewerId ? User::withoutGlobalScopes()->find($viewerId) : null;

        if ($operator) {
            return [
                'name' => $operator->name,
                'avatar_url' => $operator->avatar_url,
                'initial' => mb_strtoupper(mb_substr($operator->name, 0, 1)),
                'is_operator' => true,
                'status_label' => 'Онлайн',
            ];
        }

        return [
            'name' => 'AI ассистент',
            'avatar_url' => null,
            'initial' => 'AI',
            'is_operator' => false,
            'status_label' => 'Отвечает AI',
        ];
    }

    public function start(Request $request, string $siteKey): JsonResponse
    {
        $channel = $this->channel($siteKey);

        $data = $request->validate([
            'conversation_token' => ['nullable', 'string', 'max:64'],
            'visitor_name' => ['nullable', 'string', 'max:120'],
        ]);

        $conversation = $this->findConversation($channel, $data['conversation_token'] ?? null);

        if (! $conversation) {
            $conversation = $this->createConversation($channel, $data['visitor_name'] ?? null);
        }

        $conversation->loadMissing('customer');

        return response()->json([
            'conversation_token' => $conversation->external_id,
            'welcome_message' => Arr::get($channel->settings ?? [], 'welcome_message'),
            'needs_phone' => ! $conversation->customer?->phone,
            'messages' => $this->serializeMessages($conversation),
            'agent' => $this->agentPayload($conversation),
        ] + $this->appearancePayload($channel));
    }

    public function send(Request $request, string $siteKey): JsonResponse
    {
        $channel = $this->channel($siteKey);

        $data = $request->validate([
            'conversation_token' => ['required', 'string', 'max:64'],
            'body' => ['nullable', 'string', 'max:4000'],
            'attachment' => ['nullable', 'array'],
            'attachment.url' => ['required_with:attachment', 'string'],
            'attachment.path' => ['required_with:attachment', 'string'],
            'attachment.type' => ['required_with:attachment', Rule::in(['photo', 'document', 'voice'])],
            'attachment.filename' => ['nullable', 'string'],
            'attachment.mime' => ['nullable', 'string'],
            'attachment.transcript' => ['nullable', 'string'],
        ]);

        $body = trim((string) ($data['body'] ?? ''));
        $attachment = $data['attachment'] ?? null;

        if ($body === '' && ! $attachment) {
            abort(422, 'Message body or attachment is required.');
        }

        $conversation = $this->findConversation($channel, $data['conversation_token']);

        if (! $conversation) {
            abort(404);
        }

        // Phone is mandatory before chatting — the widget UI hides the composer
        // until it's given, but that's client-side only, so enforce it here too
        // (same rule AiWorkflow::process() enforces for Telegram).
        $conversation->loadMissing('customer');

        if (! $conversation->customer?->phone) {
            return response()->json(['ok' => false, 'needs_phone' => true], 422);
        }

        $message = Message::withoutGlobalScopes()->create([
            'tenant_id' => $channel->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_name' => $conversation->customer?->name ?: 'Гость с сайта',
            'body' => $body !== '' ? $body : $this->attachmentBody($attachment),
            'sent_at' => now(),
            'external_id' => 'widget-'.$conversation->id.'-'.Str::random(12),
            'meta' => $attachment ? ['attachment' => $attachment] : null,
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        // Same 2s debounce-then-supersede pattern as ChatwootWebhookHandler/
        // TelegramWebhookController — lets a burst of quick visitor messages
        // collapse into a single AI reply (see ProcessAiReplyJob::handle()).
        ProcessAiReplyJob::dispatch($channel->tenant_id, $channel->company_id, $conversation->id, $conversation->lead_id, $message->id)
            ->delay(now()->addSeconds(2));

        return response()->json(['ok' => true, 'message' => $this->serializeMessage($message)]);
    }

    /** Mirrors ConversationAttachmentController's upload — same storage path/shape, so the CRM's existing attachment rendering needs zero changes to pick these up. */
    public function attachment(Request $request, string $siteKey): JsonResponse
    {
        $channel = $this->channel($siteKey);

        $data = $request->validate([
            'conversation_token' => ['required', 'string', 'max:64'],
            'file' => ['required', 'file', 'max:20480'],
            'type' => ['required', Rule::in(['photo', 'document', 'voice'])],
        ]);

        if (! $this->findConversation($channel, $data['conversation_token'])) {
            abort(404);
        }

        $path = $data['file']->store('attachments/'.$channel->tenant_id, 'public');
        $filename = $data['file']->getClientOriginalName();
        $mime = $data['file']->getClientMimeType();
        $size = $data['file']->getSize();

        // Browser mic recordings come out as webm/opus — same remux ConversationAttachmentController
        // does for operator-recorded voice notes, so a widget voice message has the exact same
        // shape/compatibility as one sent from the CRM (playable everywhere, forwardable to Telegram).
        $transcript = null;

        if ($data['type'] === 'voice') {
            $remuxed = $this->remuxToOggOpus($channel->tenant_id, $path);

            if ($remuxed) {
                $path = $remuxed;
                $filename = pathinfo($filename, PATHINFO_FILENAME).'.ogg';
                $mime = 'audio/ogg';
                $size = Storage::disk('public')->size($path);
            }

            // So the AI can actually understand and answer the voice message instead
            // of just acknowledging it — see LlmClient::transcribeAudio()'s docblock.
            // Best-effort: a failed/unconfigured transcription just means send()
            // falls back to the generic "🎤 Голосовое сообщение" placeholder body.
            $transcript = $this->llm->transcribeAudio(Storage::disk('public')->path($path));
        }

        return response()->json([
            'url' => Storage::disk('public')->url($path),
            'path' => $path,
            'filename' => $filename,
            'mime' => $mime,
            'size' => $size,
            'type' => $data['type'],
            'transcript' => $transcript,
        ], 201);
    }

    /** @return string|null the new .ogg storage path, or null if ffmpeg failed (caller falls back to the original file) */
    private function remuxToOggOpus(int $tenantId, string $sourcePath): ?string
    {
        $sourceAbsolute = Storage::disk('public')->path($sourcePath);
        $targetRelative = 'attachments/'.$tenantId.'/'.pathinfo($sourcePath, PATHINFO_FILENAME).'.ogg';
        $targetAbsolute = Storage::disk('public')->path($targetRelative);

        $result = Process::timeout(20)->run([
            'ffmpeg', '-y', '-i', $sourceAbsolute,
            '-vn', '-ac', '1', '-ar', '48000', '-c:a', 'libopus', '-b:a', '32k',
            $targetAbsolute,
        ]);

        if (! $result->successful()) {
            Log::warning('Widget voice note ffmpeg remux to ogg/opus failed', ['error' => $result->errorOutput()]);

            return null;
        }

        Storage::disk('public')->delete($sourcePath);

        return $targetRelative;
    }

    /** Visitor submits their phone from the widget's inline capture prompt — soft/optional, see AiWorkflow::greetCustomer(). */
    public function phone(Request $request, string $siteKey): JsonResponse
    {
        $channel = $this->channel($siteKey);

        $data = $request->validate([
            'conversation_token' => ['required', 'string', 'max:64'],
            'phone' => ['required', 'string', 'min:5', 'max:30'],
        ]);

        $conversation = $this->findConversation($channel, $data['conversation_token']);

        if (! $conversation) {
            abort(404);
        }

        // ЭТАП 12.1 — this is the first moment a website-widget visitor is
        // identified by anything more reliable than "Гость с сайта", so it's the
        // right point to check whether they're actually the same real person as
        // an existing Telegram/Chatwoot/earlier-widget customer, rather than
        // just stamping the phone onto the fresh placeholder row from
        // createConversation() and leaving two permanent duplicate profiles.
        $placeholder = Customer::withoutGlobalScopes()->findOrFail($conversation->customer_id);
        $existing = $this->customers->findByPhone($channel->tenant, $channel->company, $data['phone'], excludeCustomerId: $placeholder->id);

        if ($existing) {
            $this->customers->mergeInto($existing, $placeholder);
        } else {
            $placeholder->update(['phone' => $data['phone']]);
        }

        return response()->json(['ok' => true]);
    }

    public function index(Request $request, string $siteKey): JsonResponse
    {
        $channel = $this->channel($siteKey);

        $data = $request->validate([
            'conversation_token' => ['required', 'string', 'max:64'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $conversation = $this->findConversation($channel, $data['conversation_token']);

        if (! $conversation) {
            abort(404);
        }

        return response()->json([
            'messages' => $this->serializeMessages($conversation, (int) ($data['after'] ?? 0)),
            // Same cache flag ConversationTypingController::index() already exposes
            // to the CRM's own "AI печатает" indicator — reused here so the widget
            // can show the equivalent to a visitor, not just to operators.
            'ai_generating' => (bool) Cache::get(ConversationTypingController::aiGeneratingCacheKey($conversation->id)),
            // Polled every 3s same as messages, so the header updates live within
            // seconds of an operator opening/closing the conversation.
            'agent' => $this->agentPayload($conversation),
        ]);
    }

    private function channel(string $siteKey): Channel
    {
        $token = WidgetToken::withoutGlobalScopes()->where('token', $siteKey)->first();

        abort_unless($token, 404);

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        $company = Company::withoutGlobalScopes()->where('tenant_id', $token->tenant_id)->first();

        $channel = Channel::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $token->tenant_id, 'provider' => 'website', 'name' => 'Website Widget'],
            ['company_id' => $token->company_id ?? $company?->id, 'status' => 'connected', 'external_id' => Str::random(24), 'last_synced_at' => now()]
        );

        abort_unless($channel->status === 'connected', 404);

        return $channel;
    }

    private function findConversation(Channel $channel, ?string $token): ?Conversation
    {
        if (! $token) {
            return null;
        }

        return Conversation::withoutGlobalScopes()
            ->where('channel_id', $channel->id)
            ->where('external_id', $token)
            ->first();
    }

    private function createConversation(Channel $channel, ?string $visitorName): Conversation
    {
        $name = $visitorName ?: 'Гость с сайта';

        $customer = Customer::withoutGlobalScopes()->create([
            'tenant_id' => $channel->tenant_id,
            'company_id' => $channel->company_id,
            'name' => $name,
            'source' => 'website',
            'meta' => ['widget' => true],
        ]);

        $lead = Lead::withoutGlobalScopes()->create([
            'tenant_id' => $channel->tenant_id,
            'company_id' => $channel->company_id,
            'customer_id' => $customer->id,
            'title' => 'Чат с сайта',
            'status' => 'new',
            'source' => 'website',
            'score' => 50,
        ]);

        return Conversation::withoutGlobalScopes()->create([
            'tenant_id' => $channel->tenant_id,
            'company_id' => $channel->company_id,
            'channel_id' => $channel->id,
            'customer_id' => $customer->id,
            'lead_id' => $lead->id,
            'external_id' => Str::random(32),
            'subject' => 'Чат с сайта',
            'status' => 'open',
            'priority' => 'normal',
            'last_message_at' => now(),
        ]);
    }

    /**
     * A voice attachment with a successful transcript uses the transcript itself as
     * the message body (prefixed for context) — the CRM/widget both already show
     * whatever's in `body` alongside the attachment, and AiWorkflow's prompt-building
     * just reads `$message->body`, so this is the only change needed for the AI to
     * actually understand and answer a voice message instead of just acknowledging
     * it. Falls back to the old generic placeholder when there's no transcript
     * (transcription unconfigured/failed) or for non-voice attachments.
     */
    private function attachmentBody(?array $attachment): string
    {
        $transcript = trim((string) ($attachment['transcript'] ?? ''));

        if (($attachment['type'] ?? null) === 'voice' && $transcript !== '') {
            return '🎤 '.$transcript;
        }

        return match ($attachment['type'] ?? null) {
            'photo' => '📷 Фото',
            'voice' => '🎤 Голосовое сообщение',
            'document' => '📎 '.($attachment['filename'] ?? 'Файл'),
            default => '',
        };
    }

    private function serializeMessages(Conversation $conversation, int $afterId = 0): array
    {
        return Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('id', '>', $afterId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Message $message) => $this->serializeMessage($message))
            ->all();
    }

    private function serializeMessage(Message $message): array
    {
        return [
            'id' => $message->id,
            'sender_type' => $message->sender_type,
            'body' => $message->body,
            'sent_at' => $message->sent_at?->toIso8601String(),
            'attachment' => Arr::get($message->meta ?? [], 'attachment'),
        ];
    }
}
