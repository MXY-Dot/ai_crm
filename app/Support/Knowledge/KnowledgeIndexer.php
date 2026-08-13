<?php

namespace App\Support\Knowledge;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KnowledgeIndexer
{
    public function indexText(array $data): KnowledgeDocument
    {
        return DB::transaction(function () use ($data): KnowledgeDocument {
            $content = trim((string) $data['content']);
            $chunks = $this->chunk($content);

            $document = KnowledgeDocument::query()->create([
                'company_id' => $data['company_id'],
                'ai_agent_id' => $data['ai_agent_id'] ?? null,
                'title' => $data['title'],
                'source_type' => $data['source_type'] ?? 'manual',
                'file_name' => $data['file_name'] ?? Str::slug($data['title']).'.txt',
                'mime_type' => $data['mime_type'] ?? 'text/plain',
                'status' => 'indexed',
                'version' => $data['version'] ?? 1,
                'summary' => $data['summary'] ?? $this->summary($content),
                'meta' => array_merge(['indexed_by' => 'local-text-indexer'], $data['meta'] ?? []),
                'indexed_at' => now(),
            ]);

            foreach ($chunks as $position => $chunk) {
                KnowledgeChunk::query()->create([
                    'knowledge_document_id' => $document->id,
                    'position' => $position + 1,
                    'content' => $chunk,
                    'token_count' => $this->tokens($chunk),
                    'meta' => ['source' => 'text'],
                ]);
            }

            return $document->fresh('chunks');
        });
    }

    /**
     * Replaces an existing document's content in place — used by the "edit" action
     * on the Knowledge Base page. Works the same regardless of how the document was
     * originally created: for a pasted-text document it's a normal content edit; for
     * an uploaded PDF/DOCX/XLSX still sitting in 'queued' status (no automated parser
     * exists for those formats yet — see indexUploadedFile()), this is also how an
     * operator can manually paste in the extracted text and turn it into a real,
     * searchable document instead of leaving it stuck unparsed forever.
     */
    public function reindexText(KnowledgeDocument $document, string $content, ?string $title = null): KnowledgeDocument
    {
        return DB::transaction(function () use ($document, $content, $title): KnowledgeDocument {
            $content = trim($content);
            $chunks = $this->chunk($content);

            $document->update([
                'title' => $title !== null && $title !== '' ? $title : $document->title,
                'status' => 'indexed',
                'version' => $document->version + 1,
                'summary' => $this->summary($content),
                'indexed_at' => now(),
            ]);

            $document->chunks()->delete();

            foreach ($chunks as $position => $chunk) {
                KnowledgeChunk::query()->create([
                    'knowledge_document_id' => $document->id,
                    'position' => $position + 1,
                    'content' => $chunk,
                    'token_count' => $this->tokens($chunk),
                    'meta' => ['source' => 'text'],
                ]);
            }

            return $document->fresh('chunks');
        });
    }

    public function indexUploadedFile(array $data): KnowledgeDocument
    {
        /** @var UploadedFile $file */
        $file = $data['file'];
        $tenantId = app(TenantContext::class)->id();
        $path = $file->store('knowledge/'.$tenantId);
        $fileName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getClientMimeType() ?: $file->getMimeType();
        $title = $data['title'] ?? pathinfo($fileName, PATHINFO_FILENAME);

        $meta = [
            'uploaded_by' => 'knowledge-upload',
            'storage_disk' => config('filesystems.default', 'local'),
            'storage_path' => $path,
            'extension' => $extension,
            'original_size' => $file->getSize(),
        ];

        if ($this->canReadAsText($extension, (string) $mimeType)) {
            $content = trim((string) file_get_contents($file->getRealPath()));

            if (mb_strlen($content) >= 20) {
                return $this->indexText([
                    'company_id' => $data['company_id'],
                    'ai_agent_id' => $data['ai_agent_id'] ?? null,
                    'title' => $title,
                    'content' => $content,
                    'source_type' => 'manual',
                    'file_name' => $fileName,
                    'mime_type' => $mimeType,
                    'summary' => $data['summary'] ?? null,
                    'meta' => $meta,
                ]);
            }
        }

        return KnowledgeDocument::query()->create([
            'company_id' => $data['company_id'],
            'ai_agent_id' => $data['ai_agent_id'] ?? null,
            'title' => $title,
            'source_type' => $this->sourceType($extension),
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'status' => 'queued',
            'version' => 1,
            'summary' => 'Uploaded file is waiting for a document parser worker.',
            'meta' => array_merge($meta, ['parser_status' => 'pending']),
        ]);
    }

    private function canReadAsText(string $extension, string $mimeType): bool
    {
        return in_array($extension, ['txt', 'md', 'csv', 'json'], true)
            || str_starts_with($mimeType, 'text/')
            || in_array($mimeType, ['application/json', 'text/markdown'], true);
    }

    private function sourceType(string $extension): string
    {
        return match ($extension) {
            'pdf' => 'pdf',
            'docx' => 'docx',
            'xlsx' => 'xlsx',
            default => 'manual',
        };
    }

    private function chunk(string $content): array
    {
        $paragraphs = preg_split('/\R{2,}/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim(preg_replace('/\s+/u', ' ', $paragraph) ?? '');

            if ($paragraph === '') {
                continue;
            }

            $candidate = trim($current.' '.$paragraph);

            if ($current !== '' && $this->tokens($candidate) > 140) {
                $chunks[] = $current;
                $current = $paragraph;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks !== [] ? $chunks : [$content];
    }

    private function summary(string $content): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', $content) ?? ''), 180);
    }

    private function tokens(string $content): int
    {
        return count(preg_split('/\s+/u', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}