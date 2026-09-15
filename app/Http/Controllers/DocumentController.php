<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\DocumentProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function index()
    {
        return Document::latest()->get();
    }

    public function store(Request $request, DocumentProcessingService $processor)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['pdf', 'docx', 'txt', 'md'], true)) {
            return response()->json([
                'message' => 'Unsupported file type. Allowed: pdf, docx, txt, md.',
            ], 422);
        }

        $storagePath = $file->storeAs('documents', Str::uuid().'.'.$extension);

        $document = Document::create([
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'storage_path' => $storagePath,
            'status' => 'processing',
        ]);

        $processor->process($document);

        return response()->json($document->fresh(), 201);
    }

    public function destroy(Document $document)
    {
        Storage::disk('local')->delete($document->storage_path);
        $document->delete();

        return response()->noContent();
    }
}
