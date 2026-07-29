<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pdf' => [
        'url' => env('PDF_SERVICE_URL', 'http://127.0.0.1:8001'),
        'secret' => env('PDF_SERVICE_SECRET'),
        'timeout' => (int) env('PDF_SERVICE_TIMEOUT', 30),
        // Heavy calls (OCR, Word export) run inside a queued job, so they get a longer budget.
        'export_timeout' => (int) env('PDF_SERVICE_EXPORT_TIMEOUT', 300),
        'max_upload_mb' => (int) env('PDF_MAX_UPLOAD_MB', 25),
        'max_pages' => (int) env('PDF_MAX_PAGES', 500),
    ],

    /*
    | AI assistant (Phase 6). The LLM provider key lives in the Python service only — these are
    | orchestration knobs for Laravel: retrieval, token/length caps, and the per-user rate limit.
    | `model` is informational (shown in the UI); the actual model is configured in pdf-service.
    */
    'ai' => [
        'model' => env('AI_MODEL', 'claude-opus-4-8'),
        'language' => env('AI_OCR_LANGUAGE', 'eng'),
        // Which LLM backend the assistant uses, switchable per request from the panel toggle.
        // `default_provider` is the initial choice; `providers` lists what the toggle offers
        // (label + model name are display-only — the real model is configured in pdf-service).
        // The actual provider keys/URLs live only in pdf-service/.env (never in Laravel).
        'default_provider' => env('AI_DEFAULT_PROVIDER', 'ollama'),
        'providers' => [
            'ollama' => ['label' => 'Local (Ollama)', 'model' => env('OLLAMA_MODEL', 'qwen2.5:3b')],
            'gemini' => ['label' => 'Gemini', 'model' => env('GEMINI_MODEL', 'gemini-2.0-flash')],
        ],
        // Chunking for RAG: target chars per chunk and the overlap carried between chunks.
        'chunk_size' => (int) env('AI_CHUNK_SIZE', 1200),
        'chunk_overlap' => (int) env('AI_CHUNK_OVERLAP', 200),
        // How many chunks the retriever feeds the model as grounding context per question.
        'retrieval_top_k' => (int) env('AI_RETRIEVAL_TOP_K', 5),
        // Cap the text sent for summarize/translate so a huge document can't blow the token budget.
        'max_input_chars' => (int) env('AI_MAX_INPUT_CHARS', 40000),
        // Per-user guardrail: max AI actions (chat/summarize/translate) per minute.
        'rate_limit_per_minute' => (int) env('AI_RATE_LIMIT_PER_MINUTE', 15),
    ],

];
