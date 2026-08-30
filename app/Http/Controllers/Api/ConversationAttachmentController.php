<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ConversationAttachmentController extends Controller
{
    public function __invoke(Request $request, Conversation $conversation, TenantContext $context): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);

        if ((int) $conversation->tenant_id !== (int) $tenant->id) {
            abort(404);
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'type' => ['required', Rule::in(['photo', 'voice', 'document'])],
        ]);

        $path = $data['file']->store('attachments/'.$tenant->id, 'public');
        $filename = $data['file']->getClientOriginalName();
        $mime = $data['file']->getClientMimeType();
        $size = $data['file']->getSize();

        // Browser voice recordings come out as webm/opus, which Telegram's sendVoice
        // rejects (it only accepts an Ogg/Opus container) — remux to .ogg here, once,
        // at upload time, so both the CRM's own playback and the later Telegram send
        // (ConversationReplyController) work off the same Telegram-compatible file.
        if ($data['type'] === 'voice') {
            $remuxed = $this->remuxToOggOpus($tenant->id, $path);

            if ($remuxed) {
                $path = $remuxed;
                $filename = pathinfo($filename, PATHINFO_FILENAME).'.ogg';
                $mime = 'audio/ogg';
                $size = Storage::disk('public')->size($path);
            }
        }

        return response()->json([
            'url' => Storage::disk('public')->url($path),
            'path' => $path,
            'filename' => $filename,
            'mime' => $mime,
            'size' => $size,
            'type' => $data['type'],
        ], 201);
    }

    /** @return string|null the new .ogg storage path, or null if ffmpeg failed (caller falls back to the original file) */
    private function remuxToOggOpus(int $tenantId, string $sourcePath): ?string
    {
        $sourceAbsolute = Storage::disk('public')->path($sourcePath);
        $targetRelative = 'attachments/'.$tenantId.'/'.pathinfo($sourcePath, PATHINFO_FILENAME).'.ogg';
        $targetAbsolute = Storage::disk('public')->path($targetRelative);

        // -application voip -vbr on -compression_level 10 are not cosmetic here — found
        // live: WhatsApp's Cloud API rejected every voice note sent without them, always
        // with the same error (code 131053, "uploaded with mimetype audio/ogg;
        // codecs=opus, however on processing it is of type application/octet-stream"),
        // even though the file was already a completely valid Ogg/Opus stream by every
        // other measure (`file`, `ffprobe`, correct Content-Type, correct headers) and
        // failed identically across three independent delivery paths (link-based send,
        // pre-uploaded media id via Laravel's HTTP client, and the same upload via raw
        // curl) -- ruling out the delivery method and HTTP client as the cause. Re-
        // encoding the exact same source with just these three extra flags added was
        // the only change that made WhatsApp accept it; confirmed end-to-end via the
        // real delivery-status webhook (sent -> delivered -> read). Telegram (this
        // method's original target) has no such requirement but accepts this encoding
        // too, so this isn't a regression for that path.
        $result = Process::timeout(20)->run([
            'ffmpeg', '-y', '-i', $sourceAbsolute,
            '-vn', '-ac', '1', '-ar', '48000', '-c:a', 'libopus', '-b:a', '32k',
            '-application', 'voip', '-vbr', 'on', '-compression_level', '10',
            $targetAbsolute,
        ]);

        if (! $result->successful()) {
            Log::warning('Voice note ffmpeg remux to ogg/opus failed', ['error' => $result->errorOutput()]);

            return null;
        }

        Storage::disk('public')->delete($sourcePath);

        return $targetRelative;
    }
}
