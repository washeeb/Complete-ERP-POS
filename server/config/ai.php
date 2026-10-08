<?php

return [
    // Claude API key: only in the server's .env (never in the database or the repository). Empty = Claude unavailable.
    'anthropic_key' => env('ANTHROPIC_API_KEY'),

    // USD per million tokens [input, output], for the monthly budget. Check the Anthropic price list when changing models.
    'claude_prices' => [
        'default' => [3.0, 15.0],
        'claude-sonnet-5-5' => [3.0, 15.0],
        'claude-haiku-5-5' => [1.0, 5.0],
        'claude-opus-5-5' => [15.0, 75.0],
    ],
];
