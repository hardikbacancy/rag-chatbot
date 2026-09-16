<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatFlowTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGemini(): void
    {
        Http::fake([
            '*embedContent*' => Http::response([
                'embedding' => ['values' => array_fill(0, 768, 0.02)],
            ]),
            '*streamGenerateContent*' => Http::response(
                "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Mockville \"}]}}]}\n\n".
                "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"is the capital.\"}]}}]}\n\n"
            ),
        ]);
    }

    private function readyDocumentFor(User $user): Document
    {
        $document = Document::create([
            'user_id' => $user->id,
            'original_filename' => 'facts.txt',
            'mime_type' => 'text/plain',
            'file_size' => 100,
            'storage_path' => 'documents/facts.txt',
            'status' => 'ready',
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'The capital of Testland is Mockville.',
            'embedding' => array_fill(0, 768, 0.02),
        ]);

        return $document;
    }

    public function test_full_chat_flow_streams_answer_and_stores_sources(): void
    {
        $user = User::factory()->create();
        $chunk = $this->readyDocumentFor($user)->chunks()->first();

        $this->fakeGemini();

        $conversation = $user->conversations()->create();

        $response = $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/messages", [
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

    public function test_previous_turns_are_sent_to_the_model_as_conversation_memory(): void
    {
        $user = User::factory()->create();
        $this->readyDocumentFor($user);

        $this->fakeGemini();

        $conversation = $user->conversations()->create();
        $conversation->messages()->create(['role' => 'user', 'content' => 'What is the capital of Testland?']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'Mockville is the capital.']);

        $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'How big is it?'])
            ->streamedContent();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'streamGenerateContent')) {
                return false;
            }

            $contents = $request->data()['contents'];

            return count($contents) === 3
                && $contents[0]['role'] === 'user'
                && $contents[0]['parts'][0]['text'] === 'What is the capital of Testland?'
                && $contents[1]['role'] === 'model'
                && $contents[2]['role'] === 'user'
                && str_contains($contents[2]['parts'][0]['text'], 'How big is it?');
        });
    }

    public function test_retrieval_ignores_documents_owned_by_other_users(): void
    {
        $owner = User::factory()->create();
        $this->readyDocumentFor($owner);

        $stranger = User::factory()->create();

        $this->fakeGemini();

        $conversation = $stranger->conversations()->create();

        $streamed = $this->actingAs($stranger)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'What is the capital?'])
            ->streamedContent();

        $this->assertStringNotContainsString('Mockville is the capital.', explode('"type":"token"', $streamed)[0]);
        $this->assertStringContainsString('"sources":[]', $streamed);
        $this->assertDatabaseCount('message_sources', 0);
    }

    public function test_conversation_can_be_fetched_and_deleted(): void
    {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['title' => 'Test']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Hi']);

        $this->actingAs($user)->getJson("/api/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('messages.0.content', 'Hi');

        $this->actingAs($user)->deleteJson("/api/conversations/{$conversation->id}")->assertNoContent();
        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
    }

    public function test_another_users_conversation_is_not_reachable(): void
    {
        $owner = User::factory()->create();
        $conversation = $owner->conversations()->create(['title' => 'Private']);

        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson("/api/conversations/{$conversation->id}")->assertNotFound();
        $this->actingAs($stranger)->deleteJson("/api/conversations/{$conversation->id}")->assertNotFound();
        $this->actingAs($stranger)
            ->postJson("/api/conversations/{$conversation->id}/messages", ['content' => 'Hello'])
            ->assertNotFound();

        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
    }

    public function test_guests_cannot_reach_the_chat_endpoints(): void
    {
        $conversation = Conversation::create(['title' => 'Test']);

        $this->getJson("/api/conversations/{$conversation->id}")->assertUnauthorized();
        $this->postJson('/api/conversations', [])->assertUnauthorized();
    }
}
