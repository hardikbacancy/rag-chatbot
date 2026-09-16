<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $conversation = $request->user()->conversations()->create($data);

        return response()->json($conversation, 201);
    }

    public function show(Request $request, string $conversation)
    {
        return $request->user()->conversations()->findOrFail($conversation)->load([
            'messages' => fn ($query) => $query->orderBy('created_at'),
            'messages.sources' => fn ($query) => $query->orderBy('rank'),
            'messages.sources.chunk.document',
        ]);
    }

    public function destroy(Request $request, string $conversation)
    {
        $request->user()->conversations()->findOrFail($conversation)->delete();

        return response()->noContent();
    }
}
