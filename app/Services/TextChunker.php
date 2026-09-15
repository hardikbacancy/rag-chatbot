<?php

namespace App\Services;

class TextChunker
{
    /**
     * Split text into overlapping chunks of roughly $size characters.
     *
     * @return string[]
     */
    public function chunk(string $text, int $size, int $overlap): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if ($text === '') {
            return [];
        }

        $chunks = [];
        $length = mb_strlen($text);
        $step = max($size - $overlap, 1);

        for ($start = 0; $start < $length; $start += $step) {
            $piece = trim(mb_substr($text, $start, $size));

            if ($piece !== '') {
                $chunks[] = $piece;
            }

            if ($start + $size >= $length) {
                break;
            }
        }

        return $chunks;
    }
}
