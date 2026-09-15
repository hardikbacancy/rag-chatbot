<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiClient
{
    private string $baseUrl;

    private string $apiKey;

    private string $chatModel;

    private string $embeddingModel;

    private int $embeddingDimensions;

    public function __construct()
    {
        $this->baseUrl = config('gemini.base_url');
        $this->apiKey = config('gemini.api_key');
        $this->chatModel = config('gemini.chat_model');
        $this->embeddingModel = config('gemini.embedding_model');
        $this->embeddingDimensions = config('gemini.embedding_dimensions');
    }

    /**
     * @return float[]
     */
    public function embed(string $text, string $taskType): array
    {
        $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->post("{$this->baseUrl}/models/{$this->embeddingModel}:embedContent", [
                'content' => [
                    'parts' => [['text' => $text]],
                ],
                'taskType' => $taskType,
                'outputDimensionality' => $this->embeddingDimensions,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Gemini embedding request failed: '.$response->body());
        }

        $values = $response->json('embedding.values');

        if (! is_array($values)) {
            throw new RuntimeException('Gemini embedding response missing values: '.$response->body());
        }

        return $values;
    }

    /**
     * Streams the assistant answer, invoking $onToken for each text chunk as it arrives.
     * Returns the full concatenated answer text.
     */
    public function streamAnswer(string $systemInstruction, string $userPrompt, callable $onToken): string
    {
        $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->withOptions(['stream' => true])
            ->post("{$this->baseUrl}/models/{$this->chatModel}:streamGenerateContent?alt=sse", [
                'system_instruction' => [
                    'parts' => [['text' => $systemInstruction]],
                ],
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $userPrompt]]],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Gemini chat request failed: '.$response->body());
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $fullText = '';

        while (! $body->eof()) {
            $buffer .= str_replace("\r\n", "\n", $body->read(1024));

            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                foreach (explode("\n", $event) as $line) {
                    if (! str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $json = trim(substr($line, 5));

                    if ($json === '' || $json === '[DONE]') {
                        continue;
                    }

                    $decoded = json_decode($json, true);
                    $piece = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';

                    if ($piece !== '') {
                        $fullText .= $piece;
                        $onToken($piece);
                    }
                }
            }
        }

        return $fullText;
    }
}
