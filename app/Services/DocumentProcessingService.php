<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DocumentProcessingService
{
    public function __construct(
        private readonly TextExtractor $extractor,
        private readonly TextChunker $chunker,
        private readonly GeminiClient $gemini,
    ) {}

    public function process(Document $document): void
    {
        try {
            $absolutePath = Storage::disk('local')->path($document->storage_path);
            $text = $this->extractor->extract($absolutePath, $document->mime_type);

            $pieces = $this->chunker->chunk(
                $text,
                config('gemini.rag.chunk_size'),
                config('gemini.rag.chunk_overlap'),
            );

            if (empty($pieces)) {
                $document->update(['status' => 'failed']);

                return;
            }

            foreach ($pieces as $index => $content) {
                $embedding = $this->gemini->embed($content, 'RETRIEVAL_DOCUMENT');

                $document->chunks()->create([
                    'chunk_index' => $index,
                    'content' => $content,
                    'embedding' => $embedding,
                ]);
            }

            $document->update(['status' => 'ready']);
        } catch (Throwable $e) {
            $document->update(['status' => 'failed']);

            throw $e;
        }
    }
}
