<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\RagChatService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
    public function store(Request $request, Conversation $conversation, RagChatService $rag)
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:8000'],
        ]);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => $data['content'],
        ]);

        return new StreamedResponse(function () use ($conversation, $data, $rag) {
            $emit = function (array $payload) {
                echo 'data: '.json_encode($payload)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $message = $rag->answer(
                $conversation,
                $data['content'],
                onSources: function (array $chunks) use ($emit) {
                    $emit([
                        'type' => 'sources',
                        'sources' => array_map(fn ($c, $rank) => [
                            'rank' => $rank + 1,
                            'filename' => $c['filename'],
                            'chunk_index' => $c['chunk_index'],
                            'similarity' => $c['similarity'],
                            'content' => $c['content'],
                            'metadata' => $c['metadata'],
                        ], $chunks, array_keys($chunks)),
                    ]);
                },
                onToken: function (string $text) use ($emit) {
                    $emit(['type' => 'token', 'text' => $text]);
                },
            );

            $emit(['type' => 'done', 'message_id' => $message->id]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
