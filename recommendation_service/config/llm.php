<?php

return [

    'driver' => env('LLM_DRIVER', 'gemini'),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'chat_model' => env('GEMINI_CHAT_MODEL', 'gemini-3.8-flash'),
        'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
        'temperature' => (float) env('GEMINI_TEMPERATURE', 0.3),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'embedding_dimensions' => (int) env('GEMINI_EMBEDDING_DIMENSIONS', 768),
        // Per HTTP call + shared chat budget (extract+embed+chat), seconds.
        'timeout' => (int) env('GEMINI_TIMEOUT', 12),
    ],

];
