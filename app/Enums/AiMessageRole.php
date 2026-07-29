<?php

namespace App\Enums;

use App\Models\AiMessage;

/**
 * The author of an {@see AiMessage} in an AI conversation. Mirrors the roles the
 * LLM understands; ``system`` is reserved for future prompt scaffolding (not user-authored).
 */
enum AiMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case System = 'system';
}
