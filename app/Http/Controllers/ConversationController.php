<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $conversation = Conversation::create($data);

        return response()->json($conversation, 201);
    }

    public function show(Conversation $conversation)
    {
        return $conversation->load([
            'messages' => fn ($query) => $query->orderBy('created_at'),
            'messages.sources' => fn ($query) => $query->orderBy('rank'),
            'messages.sources.chunk.document',
        ]);
    }

    public function destroy(Conversation $conversation)
    {
        $conversation->delete();

        return response()->noContent();
    }
}
