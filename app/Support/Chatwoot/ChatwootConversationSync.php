<?php

namespace App\Support\Chatwoot;

use App\Models\Tenant;
use App\Support\Inbox\ChatwootWebhookHandler;
use Illuminate\Validation\ValidationException;

class ChatwootConversationSync
{
    public function __construct(
        private readonly ChatwootClient $client,
        private readonly ChatwootPayloadMapper $mapper,
        private readonly ChatwootWebhookHandler $handler,
    ) {
    }

    public function sync(Tenant $tenant): array
    {
        $imported = 0;
        $duplicates = 0;
        $skipped = 0;
        $conversations = [];

        foreach ($this->client->conversations($tenant) as $remoteConversation) {
            if (! is_array($remoteConversation)) {
                $skipped++;
                continue;
            }

            $message = $this->mapper->latestIncomingMessage($remoteConversation);

            if (! $message) {
                $skipped++;
                continue;
            }

            $payload = $this->mapper->fromConversation($remoteConversation, $message);

            if (($payload['message']['content'] ?? '') === '') {
                $skipped++;
                continue;
            }

            try {
                $result = $this->handler->handle($tenant, $payload);
            } catch (ValidationException) {
                $skipped++;
                continue;
            }

            $conversations[] = $result['conversation'];

            if (! empty($result['duplicate'])) {
                $duplicates++;
            } else {
                $imported++;
            }
        }

        return [
            'imported' => $imported,
            'duplicates' => $duplicates,
            'skipped' => $skipped,
            'conversations' => $conversations,
        ];
    }
}