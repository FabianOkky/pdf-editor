<?php

namespace App\Enums;

/**
 * Lifecycle state of a document while it is processed by the PDF service.
 */
enum DocumentStatus: string
{
    case Ready = 'ready';
    case Processing = 'processing';
    case Failed = 'failed';
}
