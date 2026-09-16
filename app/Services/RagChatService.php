<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class RagChatService
{
    /** Number of previous messages replayed to the model as conversation memory. */
    private const HISTORY_LIMIT = 10;

    private const GROUNDING_PROMPT = <<<'PROMPT'
        You are a document-grounded assistant.
        Answer ONLY using the document context provided in the latest message.
        Do not use outside knowledge or invent information.
        Earlier turns in this conversation are there to resolve references such as
        "it" or "that year"; they are not a source of facts on their own.
        If the context does not contain enough information to answer,
        say that the uploaded documents do not contain enough information.
        PROMPT;

    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * @return array{chunk_id: int, content: string, filename: string, chunk_index: int, metadata: ?array, similarity: float}[]
     */
    public function retrieveRelevantChunks(string $question, int $userId): array
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
             WHERE d.status = ? AND d.user_id = ?
             ORDER BY dc.embedding <=> ?::vector
             LIMIT ?',
            [$vectorLiteral, 'ready', $userId, $vectorLiteral, $topK]
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

    public function answer(Conversation $conversation, Message $userMessage, callable $onSources, callable $onToken): Message
    {
        $question = $userMessage->content;

        $chunks = $this->retrieveRelevantChunks(
            $this->retrievalQuery($conversation, $userMessage),
            $conversation->user_id,
        );

        $onSources($chunks);

        $contents = $this->historyContents($conversation, $userMessage);
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $this->buildPrompt($question, $chunks)]],
        ];

        $answerText = $this->gemini->streamAnswer(self::GROUNDING_PROMPT, $contents, $onToken);

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

    /**
     * A follow-up like "and in 2024?" embeds poorly on its own, so the previous
     * question is prepended to give the vector search something to match against.
     */
    private function retrievalQuery(Conversation $conversation, Message $userMessage): string
    {
        $previousQuestion = $conversation->messages()
            ->where('id', '<', $userMessage->id)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->value('content');

        return $previousQuestion === null
            ? $userMessage->content
            : $previousQuestion."\n".$userMessage->content;
    }

    /**
     * The last few turns as Gemini `contents`. Gemini expects the exchange to
     * start with a user turn, so any leading assistant turns are dropped.
     *
     * @return array<int, array{role: string, parts: array<int, array{text: string}>}>
     */
    private function historyContents(Conversation $conversation, Message $userMessage): array
    {
        $history = $conversation->messages()
            ->where('id', '<', $userMessage->id)
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->values();

        while ($history->isNotEmpty() && $history->first()->role !== 'user') {
            $history->shift();
        }

        return $history
            ->map(fn (Message $message) => [
                'role' => $message->role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $message->content]],
            ])
            ->values()
            ->all();
    }
}
