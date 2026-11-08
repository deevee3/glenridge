<?php

return [
    /*
    |--------------------------------------------------------------------------
    | LLM Provider
    |--------------------------------------------------------------------------
    |
    | The LLM provider to use for event parsing. Supported: "openai"
    |
    */
    'provider' => env('LLM_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for OpenAI API integration. Requires an API key.
    |
    */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'cheap_model' => env('OPENAI_CHEAP_MODEL', 'gpt-4o-mini'),
        'expensive_model' => env('OPENAI_EXPENSIVE_MODEL', 'gpt-4o'),
        'max_tokens' => env('OPENAI_MAX_TOKENS', 2000),
        'temperature' => env('OPENAI_TEMPERATURE', 0.2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conditional Compute Configuration
    |--------------------------------------------------------------------------
    |
    | Rules for routing requests to cheap vs expensive models to optimize costs.
    |
    */
    'conditional_compute' => [
        'enabled' => env('LLM_CONDITIONAL_COMPUTE', true),
        'text_length_threshold' => 500,
        'complexity_keywords' => [
            'multiple',
            'various',
            'several',
            'complex',
            'numerous',
            'widespread',
            'extensive',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Extraction Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for event parsing and extraction from text.
    |
    */
    'extraction' => [
        'max_events_per_text' => 10,
        'min_confidence_threshold' => 0.5,
        'fuzzy_match_threshold' => 0.8,
    ],
];
