<?php

return [
    'api_key' => env('GEMINI_API_KEY'),

    'chat_model' => env('GEMINI_CHAT_MODEL', 'gemini-3.5-flash-lite'),

    'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),

    'embedding_dimensions' => 768,

    'base_url' => 'https://generativelanguage.googleapis.com/v1beta',

    'rag' => [
        'chunk_size' => (int) env('RAG_CHUNK_SIZE', 800),
        'chunk_overlap' => (int) env('RAG_CHUNK_OVERLAP', 100),
        'top_k' => (int) env('RAG_TOP_K', 5),
    ],
];
