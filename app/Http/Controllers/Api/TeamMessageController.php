<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeamMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** 1-on-1 internal team chat -- separate from the customer-facing Inbox
 *  (conversations/messages), no channel/lead/AI concept, just colleagues.
 *  Same attachment/edit/delete/reply field shapes as the customer-facing
 *  MessageController/ConversationReplyController/ConversationAttachmentController
 *  so the frontend reuses their exact types -- minus any external-channel
 *  delivery (Telegram/WhatsApp sync, provider-specific voice remuxing),
 *  since a team message never leaves the CRM. */
class TeamMessageController extends Controller
{
    public function threads(Request $request): JsonResponse
    {
        $user = $request->user();

        $colleagues = User::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'role', 'avatar_path', 'last_seen_at']);

        $threads = $colleagues->map(function (User $colleague) use ($user): array {
            $last = TeamMessage::query()
                ->where(function ($q) use ($user, $colleague): void {
                    $q->where('sender_id', $user->id)->where('recipient_id', $colleague->id);
                })
                ->orWhere(function ($q) use ($user, $colleague): void {
                    $q->where('sender_id', $colleague->id)->where('recipient_id', $user->id);
                })
                ->latest('id')
                ->first();

            return [
                'user' => $colleague,
                'last_message' => $this->previewFor($last),
                'last_message_at' => $last?->created_at,
                'unread_count' => TeamMessage::query()
                    ->where('sender_id', $colleague->id)
                    ->where('recipient_id', $user->id)
                    ->whereNull('read_at')
                    ->count(),
            ];
        })->sortByDesc(fn (array $thread) => $thread['last_message_at'] ?? '0')->values();

        return response()->json($threads);
    }

    public function messages(Request $request, User $colleague): JsonResponse
    {
        $user = $request->user();
        abort_unless($colleague->tenant_id === $user->tenant_id, 404);

        $messages = TeamMessage::query()
            ->with(['sender:id,name,avatar_path', 'replyTo.sender:id,name,avatar_path'])
            ->where(function ($q) use ($user, $colleague): void {
                $q->where('sender_id', $user->id)->where('recipient_id', $colleague->id);
            })
            ->orWhere(function ($q) use ($user, $colleague): void {
                $q->where('sender_id', $colleague->id)->where('recipient_id', $user->id);
            })
            ->orderBy('id')
            ->get();

        TeamMessage::query()
            ->where('sender_id', $colleague->id)
            ->where('recipient_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json($messages);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'body' => ['nullable', 'string', 'max:4000'],
            'reply_to_message_id' => ['nullable', Rule::exists('team_messages', 'id')],
            'attachment' => ['nullable', 'array'],
            'attachment.url' => ['required_with:attachment', 'string'],
            'attachment.path' => ['required_with:attachment', 'string'],
            'attachment.type' => ['required_with:attachment', Rule::in(['photo', 'voice', 'document'])],
            'attachment.filename' => ['nullable', 'string'],
            'attachment.mime' => ['nullable', 'string'],
        ]);

        $recipient = User::query()->findOrFail($data['recipient_id']);
        abort_unless($recipient->tenant_id === $user->tenant_id, 404);

        $body = trim((string) ($data['body'] ?? ''));
        $attachment = $data['attachment'] ?? null;

        if ($body === '' && ! $attachment) {
            throw ValidationException::withMessages(['body' => 'Message body or attachment is required.']);
        }

        $message = TeamMessage::query()->create([
            'sender_id' => $user->id,
            'recipient_id' => $recipient->id,
            'reply_to_message_id' => $data['reply_to_message_id'] ?? null,
            'body' => $body !== '' ? $body : $this->attachmentLabel($attachment),
            'meta' => $attachment ? ['attachment' => $attachment] : null,
        ]);

        return response()->json($message->fresh()->load(['sender:id,name,avatar_path', 'replyTo.sender:id,name,avatar_path']), 201);
    }

    public function update(Request $request, TeamMessage $message): JsonResponse
    {
        $user = $request->user();
        abort_unless($message->tenant_id === $user->tenant_id, 404);

        if ($message->sender_id !== $user->id) {
            throw ValidationException::withMessages(['message' => 'Only your own messages can be edited.']);
        }
        if ($message->deleted_at) {
            throw ValidationException::withMessages(['message' => 'This message was deleted.']);
        }

        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $message->forceFill(['body' => trim($data['body']), 'edited_at' => now()])->save();

        return response()->json($message->fresh()->load(['sender:id,name,avatar_path', 'replyTo.sender:id,name,avatar_path']));
    }

    public function destroy(Request $request, TeamMessage $message): JsonResponse
    {
        $user = $request->user();
        abort_unless($message->tenant_id === $user->tenant_id, 404);

        if ($message->sender_id !== $user->id) {
            throw ValidationException::withMessages(['message' => 'Only your own messages can be deleted.']);
        }

        $message->forceFill(['deleted_at' => now(), 'body' => '', 'meta' => null])->save();

        return response()->json($message->fresh());
    }

    public function uploadAttachment(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());

        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'type' => ['required', Rule::in(['photo', 'voice', 'document'])],
        ]);

        // Unlike ConversationAttachmentController, a team message never leaves
        // the CRM (no Telegram/WhatsApp/Instagram delivery), so there's no
        // provider-specific voice remux to do -- the browser's own webm/opus
        // recording plays back fine in a plain <audio> element as-is.
        $path = $data['file']->store('attachments/team/'.$tenant->id, 'public');

        return response()->json([
            'url' => Storage::disk('public')->url($path),
            'path' => $path,
            'filename' => $data['file']->getClientOriginalName(),
            'mime' => $data['file']->getClientMimeType(),
            'size' => $data['file']->getSize(),
            'type' => $data['type'],
        ], 201);
    }

    private function previewFor(?TeamMessage $message): ?string
    {
        if (! $message) return null;
        if ($message->deleted_at) return null;

        return $message->body;
    }

    private function attachmentLabel(?array $attachment): string
    {
        return match ($attachment['type'] ?? null) {
            'photo' => '📷 Фото',
            'voice' => '🎤 Голосовое сообщение',
            'document' => '📎 '.($attachment['filename'] ?? 'Файл'),
            default => '',
        };
    }
}
