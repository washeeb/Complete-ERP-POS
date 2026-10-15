<?php

return [
    // Claude API key: only in the server's .env (never in the database or the repository). Empty = Claude unavailable.
    'anthropic_key' => env('ANTHROPIC_API_KEY'),

    // Key for "Another cloud model" (Gemini, Groq, OpenRouter, Mistral, OpenAI or any OpenAI-compatible service): .env only.
    'cloud_key' => env('AI_CLOUD_API_KEY'),

    // OpenAI-compatible services: address and usual models [text, vision]. Services rename models; System → Settings → AI
    // can name another. Free plans change too: see each service's pricing page.
    'cloud_services' => [
        'gemini' => ['url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'model' => 'gemini-2.5-flash', 'vision' => 'gemini-2.5-flash'],
        'groq' => ['url' => 'https://api.groq.com/openai/v1', 'model' => 'llama-3.3-70b-versatile', 'vision' => 'meta-llama/llama-4-scout-17b-16e-instruct'],
        'openrouter' => ['url' => 'https://openrouter.ai/api/v1', 'model' => 'meta-llama/llama-3.3-70b-instruct:free', 'vision' => 'google/gemma-3-27b-it:free'],
        'mistral' => ['url' => 'https://api.mistral.ai/v1', 'model' => 'mistral-small-latest', 'vision' => 'mistral-small-latest'],
        'openai' => ['url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini', 'vision' => 'gpt-4o-mini'],
    ],

    // USD per million tokens [input, output], for the monthly budget. Check the Anthropic price list when changing models.
    'claude_prices' => [
        'default' => [3.0, 15.0],
        'claude-sonnet-5-5' => [3.0, 15.0],
        'claude-haiku-5-5' => [1.0, 5.0],
        'claude-opus-5-5' => [15.0, 75.0],
    ],
];
