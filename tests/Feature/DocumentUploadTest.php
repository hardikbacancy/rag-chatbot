<?php

namespace Tests\Feature;

use App\Models\Document;
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

        $file = File::createWithContent('notes.txt', str_repeat('The sky is blue. ', 100));

        $response = $this->postJson('/api/documents', ['file' => $file]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'ready');
        $response->assertJsonPath('original_filename', 'notes.txt');

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertGreaterThan(0, $document->chunks()->count());
    }

    public function test_unsupported_file_type_is_rejected(): void
    {
        $file = File::createWithContent('image.png', 'not-a-real-image');

        $response = $this->postJson('/api/documents', ['file' => $file]);

        $response->assertStatus(422);
    }

    public function test_documents_can_be_listed_and_deleted(): void
    {
        Http::fake(['*embedContent*' => Http::response($this->fakeEmbeddingResponse())]);

        $file = File::createWithContent('notes.txt', 'Some content to index.');
        $this->postJson('/api/documents', ['file' => $file])->assertCreated();

        $document = Document::first();

        $this->getJson('/api/documents')->assertOk()->assertJsonCount(1);

        $this->deleteJson("/api/documents/{$document->id}")->assertNoContent();
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
    }
}
