<?php

namespace App\Console\Commands;

use App\Models\KnowledgeChunk;
use App\Support\Ai\LlmClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ЭТАП 5.2 — one-time backfill, not scheduled: existing chunks indexed before
 * this feature shipped (and any indexed while no platform OpenAI key was
 * configured yet — see wero_pending_tasks.md) have `embedding IS NULL` and
 * fall back to the fixed-order slice in DifyClient::knowledgeContext() until
 * this runs. Safe to re-run any time; only touches chunks still missing one.
 */
class BackfillKnowledgeEmbeddingsCommand extends Command
{
    protected $signature = 'knowledge:backfill-embeddings';

    protected $description = 'Compute embeddings for knowledge chunks that do not have one yet.';

    public function handle(LlmClient $llm): int
    {
        $chunks = KnowledgeChunk::withoutGlobalScopes()
            ->whereNull('embedding')
            ->get(['id', 'content']);

        if ($chunks->isEmpty()) {
            $this->info('No chunks need embedding.');

            return self::SUCCESS;
        }

        $embedded = 0;

        foreach ($chunks as $chunk) {
            $vector = $llm->embed($chunk->content);

            if ($vector === null) {
                // No platform OpenAI key configured, or the call failed — no
                // point retrying the remaining chunks in this same run.
                $this->warn("Stopping — embedding request failed or no key configured (embedded {$embedded}/{$chunks->count()}).");

                return self::FAILURE;
            }

            DB::statement('UPDATE knowledge_chunks SET embedding = ?::vector WHERE id = ?', ['['.implode(',', $vector).']', $chunk->id]);
            $embedded++;
        }

        $this->info("Done. {$embedded} chunk(s) embedded.");

        return self::SUCCESS;
    }
}
