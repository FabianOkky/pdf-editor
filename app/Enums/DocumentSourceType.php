<?php

namespace App\Enums;

/**
 * How a document's content is stored, which decides editing & export strategy.
 */
enum DocumentSourceType: string
{
    case Native = 'native';   // real text/vector content
    case Scanned = 'scanned'; // image-only (needs OCR)
    case Mixed = 'mixed';     // some pages native, some scanned
    case Unknown = 'unknown'; // not yet analyzed
}
