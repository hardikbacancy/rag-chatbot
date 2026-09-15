<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_chat_flow_streams_answer_and_stores_sources(): void
    {
        $document = Document::create([
            'original_filename' => 'facts.txt',
            'mime_type' => 'text/plain',
            'file_size' => 100,
            'storage_path' => 'documents/facts.txt',
            'status' => 'ready',
        ]);

        $chunk = DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'The capital of Testland is Mockville.',
            'embedding' => array_fill(0, 768, 0.02),
        ]);

        Http::fake([
            '*embedContent*' => Http::response([
                'embedding' => ['values' => array_fill(0, 768, 0.02)],
            ]),
            '*streamGenerateContent*' => Http::response(
                "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Mockville \"}]}}]}\n\n".
                "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"is the capital.\"}]}}]}\n\n"
            ),
        ]);

        $conversation = Conversation::create();

        $response = $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'content' => 'What is the capital of Testland?',
        ]);

        $response->assertOk();
        $streamed = $response->streamedContent();

        $this->assertStringContainsString('"type":"sources"', $streamed);
        $this->assertStringContainsString('Mockville', $streamed);
        $this->assertStringContainsString('"type":"done"', $streamed);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'What is the capital of Testland?',
        ]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Mockville is the capital.',
        ]);

        $this->assertDatabaseHas('message_sources', [
            'document_chunk_id' => $chunk->id,
            'rank' => 1,
        ]);
    }

    public function test_conversation_can_be_fetched_and_deleted(): void
    {
        $conversation = Conversation::create(['title' => 'Test']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Hi']);

        $this->getJson("/api/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('messages.0.content', 'Hi');

        $this->deleteJson("/api/conversations/{$conversation->id}")->assertNoContent();
        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
    }
}
