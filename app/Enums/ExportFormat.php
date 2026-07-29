<?php

namespace App\Enums;

use App\Models\ExportJob;

/**
 * Output format an {@see ExportJob} produces. Phase 5 ships DOCX; PDF is reserved
 * for a future "download a flattened copy" export that reuses the same job pipeline.
 */
enum ExportFormat: string
{
    case Pdf = 'pdf';
    case Docx = 'docx';

    /**
     * The file extension for this format.
     */
    public function extension(): string
    {
        return $this->value;
    }

    /**
     * The MIME type browsers should receive when downloading this format.
     */
    public function mime(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        };
    }
}
