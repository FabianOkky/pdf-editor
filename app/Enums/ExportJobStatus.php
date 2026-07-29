<?php

namespace App\Enums;

use App\Models\ExportJob;

/**
 * Lifecycle of an asynchronous {@see ExportJob}: queued → processing → completed,
 * or failed. The UI polls this to show progress and reveal the download when it is ready.
 */
enum ExportJobStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Whether the job has reached a terminal state (no further transitions, stop polling).
     */
    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }

    /**
     * Whether the job is still in flight (queued or processing).
     */
    public function isPending(): bool
    {
        return ! $this->isFinished();
    }
}
