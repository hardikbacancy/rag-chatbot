<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private function fakeEmbeddingResponse(): array
    {
        return ['embedding' => ['values' => array_fill(0, 768, 0.01)]];
    }

    public function test_uploading_a_text_file_creates_a_ready_document_with_chunks(): void
    {
        Http::fake([
            '*embedContent*' => Http::response($this->fakeEmbeddingResponse()),
        ]);

        $user = User::factory()->create();
        $file = File::createWithContent('notes.txt', str_repeat('The sky is blue. ', 100));

        $response = $this->actingAs($user)->postJson('/api/documents', ['file' => $file]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'ready');
        $response->assertJsonPath('original_filename', 'notes.txt');

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertSame($user->id, $document->user_id);
        $this->assertGreaterThan(0, $document->chunks()->count());
    }

    public function test_unsupported_file_type_is_rejected(): void
    {
        $user = User::factory()->create();
        $file = File::createWithContent('image.png', 'not-a-real-image');

        $response = $this->actingAs($user)->postJson('/api/documents', ['file' => $file]);

        $response->assertStatus(422);
    }

    public function test_documents_can_be_listed_and_deleted(): void
    {
        Http::fake(['*embedContent*' => Http::response($this->fakeEmbeddingResponse())]);

        $user = User::factory()->create();
        $file = File::createWithContent('notes.txt', 'Some content to index.');
        $this->actingAs($user)->postJson('/api/documents', ['file' => $file])->assertCreated();

        $document = Document::first();

        $this->actingAs($user)->getJson('/api/documents')->assertOk()->assertJsonCount(1);

        $this->actingAs($user)->deleteJson("/api/documents/{$document->id}")->assertNoContent();
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
    }

    public function test_documents_are_private_to_their_owner(): void
    {
        Http::fake(['*embedContent*' => Http::response($this->fakeEmbeddingResponse())]);

        $owner = User::factory()->create();
        $file = File::createWithContent('notes.txt', 'Some content to index.');
        $this->actingAs($owner)->postJson('/api/documents', ['file' => $file])->assertCreated();

        $document = Document::first();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson('/api/documents')->assertOk()->assertJsonCount(0);
        $this->actingAs($stranger)->deleteJson("/api/documents/{$document->id}")->assertNotFound();

        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }

    public function test_guests_cannot_reach_the_document_endpoints(): void
    {
        $this->getJson('/api/documents')->assertUnauthorized();
        $this->postJson('/api/documents', [])->assertUnauthorized();
    }
}
