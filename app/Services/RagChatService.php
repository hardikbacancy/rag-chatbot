<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class RagChatService
{
    private const GROUNDING_PROMPT = <<<'PROMPT'
        You are a document-grounded assistant.
        Answer ONLY using the provided document context.
        Do not use outside knowledge or invent information.
        If the context does not contain enough information to answer,
        say that the uploaded documents do not contain enough information.
        PROMPT;

    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * @return array{chunk_id: int, content: string, filename: string, chunk_index: int, metadata: ?array, similarity: float}[]
     */
    public function retrieveRelevantChunks(string $question): array
    {
        $embedding = $this->gemini->embed($question, 'RETRIEVAL_QUERY');
        $vectorLiteral = '['.implode(',', $embedding).']';
        $topK = config('gemini.rag.top_k');

        $rows = DB::select(
            'SELECT dc.id AS chunk_id, dc.content, dc.chunk_index, dc.metadata,
                    d.original_filename AS filename,
                    1 - (dc.embedding <=> ?::vector) AS similarity
             FROM document_chunks dc
             JOIN documents d ON d.id = dc.document_id
             WHERE d.status = ?
             ORDER BY dc.embedding <=> ?::vector
             LIMIT ?',
            [$vectorLiteral, 'ready', $vectorLiteral, $topK]
        );

        return array_map(fn ($row) => [
            'chunk_id' => $row->chunk_id,
            'content' => $row->content,
            'filename' => $row->filename,
            'chunk_index' => $row->chunk_index,
            'metadata' => $row->metadata ? json_decode($row->metadata, true) : null,
            'similarity' => (float) $row->similarity,
        ], $rows);
    }

    public function buildPrompt(string $question, array $chunks): string
    {
        $context = collect($chunks)
            ->map(fn ($chunk, $i) => '[Source '.($i + 1).": {$chunk['filename']}]\n{$chunk['content']}")
            ->implode("\n\n");

        return <<<PROMPT
            Context:
            {$context}

            Question: {$question}
            PROMPT;
    }

    public function answer(Conversation $conversation, string $question, callable $onSources, callable $onToken): Message
    {
        $chunks = $this->retrieveRelevantChunks($question);
        $onSources($chunks);
        $prompt = $this->buildPrompt($question, $chunks);

        $answerText = $this->gemini->streamAnswer(self::GROUNDING_PROMPT, $prompt, $onToken);

        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $answerText,
        ]);

        foreach ($chunks as $rank => $chunk) {
            $message->sources()->create([
                'document_chunk_id' => $chunk['chunk_id'],
                'similarity_score' => $chunk['similarity'],
                'rank' => $rank + 1,
            ]);
        }

        return $message->load('sources.chunk.document');
    }
}
