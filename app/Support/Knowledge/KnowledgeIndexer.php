<?php

namespace App\Support\Knowledge;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\Ai\LlmClient;
use App\Support\Security\PromptInjectionDetector;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

class KnowledgeIndexer
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly PromptInjectionDetector $injectionDetector,
    ) {
    }

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

            $this->createChunks($document, $chunks);

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
            $this->createChunks($document, $chunks);

            return $document->fresh('chunks');
        });
    }

    /**
     * ЭТАП 5.3 — single-page website training: fetches exactly the URL the
     * operator gave (no link-following, no crawling, no schedule) and
     * indexes its extracted text the same way as pasted text.
     */
    public function indexUrl(array $data): KnowledgeDocument
    {
        $url = trim((string) $data['url']);
        $this->assertSafeUrl($url);

        try {
            // Redirects deliberately not followed — validating the target
            // URL and then letting it 302 somewhere unvalidated would defeat
            // assertSafeUrl() below entirely. A company's real site should
            // resolve directly; if it 301s, the operator can paste the final URL.
            $response = Http::timeout(10)->connectTimeout(4)
                ->withUserAgent('WERO-KnowledgeBot/1.0')
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (Throwable $error) {
            throw new RuntimeException('Не удалось обратиться по этой ссылке: '.$error->getMessage());
        }

        if (! $response->successful()) {
            throw new RuntimeException('Не удалось загрузить страницу (HTTP '.$response->status().').');
        }

        [$title, $content] = $this->extractHtml($response->body());

        if (mb_strlen($content) < 20) {
            throw new RuntimeException('На этой странице не найдено читаемого текста.');
        }

        return $this->indexText([
            'company_id' => $data['company_id'],
            'ai_agent_id' => $data['ai_agent_id'] ?? null,
            'title' => $data['title'] ?? ($title !== '' ? $title : $url),
            'content' => $content,
            'source_type' => 'url',
            'mime_type' => 'text/html',
            // ЭТАП 10.5 — visibility only: a page fetched from a third party
            // (unlike operator-pasted text) could contain adversarial content
            // aimed at the AI reading it later. Doesn't block indexing — the
            // operator explicitly chose this URL — just flags it for review.
            'meta' => ['source_url' => $url, 'contains_injection_markers' => $this->injectionDetector->detect($content)],
        ]);
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

        $content = $this->canReadAsText($extension, (string) $mimeType)
            ? trim((string) file_get_contents($file->getRealPath()))
            : $this->extractText($extension, $file->getRealPath());

        if ($content !== null && mb_strlen($content) >= 20) {
            return $this->indexText([
                'company_id' => $data['company_id'],
                'ai_agent_id' => $data['ai_agent_id'] ?? null,
                'title' => $title,
                'content' => $content,
                'source_type' => $this->sourceType($extension),
                'file_name' => $fileName,
                'mime_type' => $mimeType,
                'summary' => $data['summary'] ?? null,
                'meta' => $meta,
            ]);
        }

        // ЭТАП 5.1/5.4 — pdf/docx/xlsx that genuinely failed to parse
        // (corrupt, encrypted, scanned-image-only) get a real 'failed'
        // status with an explanation instead of sitting in 'queued' forever;
        // any other unreadable type still falls back to the original
        // queued-for-a-human-to-paste-text behavior (no parser exists for it).
        $parserAttempted = in_array($extension, ['pdf', 'docx', 'xlsx'], true);

        return KnowledgeDocument::query()->create([
            'company_id' => $data['company_id'],
            'ai_agent_id' => $data['ai_agent_id'] ?? null,
            'title' => $title,
            'source_type' => $this->sourceType($extension),
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'status' => $parserAttempted ? 'failed' : 'queued',
            'version' => 1,
            'summary' => $parserAttempted
                ? 'Could not extract readable text from this file — it may be scanned, image-only, or corrupted. Open it with the viewer and paste the text manually.'
                : 'Uploaded file is waiting for a document parser worker.',
            'meta' => array_merge($meta, ['parser_status' => $parserAttempted ? 'failed' : 'pending']),
        ]);
    }

    private function createChunks(KnowledgeDocument $document, array $chunks): void
    {
        foreach ($chunks as $position => $chunk) {
            $record = KnowledgeChunk::query()->create([
                'knowledge_document_id' => $document->id,
                'position' => $position + 1,
                'content' => $chunk,
                'token_count' => $this->tokens($chunk),
                'meta' => ['source' => 'text'],
            ]);

            $this->embedChunk($record);
        }
    }

    /**
     * ЭТАП 5.2 — best-effort: a missing platform OpenAI key (none configured
     * as of this writing, see wero_pending_tasks.md) or a failed API call
     * just leaves `embedding` null, which knowledgeContext() already treats
     * as "fall back to fixed-order chunks". Never blocks indexing on this.
     */
    private function embedChunk(KnowledgeChunk $chunk): void
    {
        $vector = $this->llm->embed($chunk->content);

        if ($vector === null) {
            return;
        }

        DB::statement('UPDATE knowledge_chunks SET embedding = ?::vector WHERE id = ?', ['['.implode(',', $vector).']', $chunk->id]);
    }

    /**
     * ЭТАП 5.1/5.4 — real parsing for the 3 formats that used to sit in
     * 'queued' forever waiting for a human to paste text manually (see
     * reindexText()'s docblock). Returns null on any parse failure (corrupt
     * file, encrypted PDF, unsupported internal structure) rather than
     * throwing — the caller turns that into a real 'failed' status instead
     * of a 500.
     */
    private function extractText(string $extension, string $realPath): ?string
    {
        try {
            return match ($extension) {
                'pdf' => trim((new PdfParser())->parseFile($realPath)->getText()),
                'docx' => $this->extractWordText($realPath),
                'xlsx' => $this->extractSpreadsheetText($realPath),
                default => null,
            };
        } catch (Throwable $error) {
            Log::warning('Knowledge document parse failed', ['extension' => $extension, 'error' => $error->getMessage()]);

            return null;
        }
    }

    private function extractWordText(string $realPath): string
    {
        $lines = [];

        foreach (WordIOFactory::load($realPath)->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $lines = array_merge($lines, $this->extractWordElementText($element));
            }
        }

        return implode("\n", array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    /**
     * Recurses into a single PhpWord element and returns the plain-text
     * line(s) it represents. Originally only handled Text/TextRun (a plain
     * paragraph) -- a table (the most common shape for a real pricing list
     * or FAQ, e.g. "Archive_2023_Pricing.docx") has no getText()/getElements()
     * of its own, so it silently produced zero lines: a real, non-corrupt
     * DOCX could come out completely empty, fall under the 20-char minimum,
     * and get marked 'failed' with no error ever logged (unlike a genuine
     * parse exception, which does get logged) -- confirmed by generating a
     * table-only test .docx and running it through this method directly.
     *
     * @return string[]
     */
    private function extractWordElementText(mixed $element): array
    {
        if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
            $lines = [];

            foreach ($element->getRows() as $row) {
                $cells = [];

                foreach ($row->getCells() as $cell) {
                    $cellLines = [];

                    foreach ($cell->getElements() as $cellElement) {
                        $cellLines = array_merge($cellLines, $this->extractWordElementText($cellElement));
                    }

                    $cellText = trim(implode(' ', array_filter($cellLines, fn (string $line): bool => $line !== '')));

                    if ($cellText !== '') {
                        $cells[] = $cellText;
                    }
                }

                if ($cells !== []) {
                    $lines[] = implode(' | ', $cells);
                }
            }

            return $lines;
        }

        if (method_exists($element, 'getText') && is_string($element->getText())) {
            return [trim($element->getText())];
        }

        if (method_exists($element, 'getElements')) {
            $runText = array_map(
                fn ($run) => method_exists($run, 'getText') && is_string($run->getText()) ? $run->getText() : '',
                $element->getElements()
            );

            return [trim(implode('', $runText))];
        }

        return [];
    }

    private function extractSpreadsheetText(string $realPath): string
    {
        $lines = [];

        foreach (SpreadsheetIOFactory::load($realPath)->getAllSheets() as $sheet) {
            foreach ($sheet->toArray(null, true, true, false) as $row) {
                $cells = array_filter(array_map('trim', array_map('strval', $row)), fn (string $cell): bool => $cell !== '');

                if ($cells !== []) {
                    $lines[] = implode(' | ', $cells);
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Basic SSRF guard — this lets an authenticated operator make the server
     * fetch an arbitrary URL, so it must not be usable to reach the server's
     * own internal network (localhost, private ranges, cloud metadata IPs).
     */
    private function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);

        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new RuntimeException('Введите корректный http(s)-адрес.');
        }

        $host = $parts['host'];
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if ($ip === $host && ! filter_var($host, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('Не удалось определить адрес этого хоста.');
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('Эта ссылка указывает на запрещённый адрес.');
        }
    }

    /**
     * @return array{0: string, 1: string} [pageTitle, plainBodyText]
     */
    private function extractHtml(string $html): array
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $titleNodes = $document->getElementsByTagName('title');
        $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';

        foreach (['script', 'style', 'nav', 'footer'] as $tag) {
            $nodes = $document->getElementsByTagName($tag);

            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $nodes->item($i)->parentNode?->removeChild($nodes->item($i));
            }
        }

        $bodyNodes = $document->getElementsByTagName('body');
        $text = $bodyNodes->length > 0 ? $bodyNodes->item(0)->textContent : $document->textContent;
        $text = preg_replace('/\s+/u', ' ', trim((string) $text)) ?? '';

        return [$title, $text];
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