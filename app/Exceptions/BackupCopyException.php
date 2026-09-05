<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * API-021: the S3 copy-off of a fresh backup failed. The local (unreferenced)
 * copy has already been cleaned by the service; the API maps this to a 502
 * and no backup row is created. The scheduler's per-schedule catch treats it
 * like any other failure — the schedule stays unadvanced and retries.
 */
class BackupCopyException extends RuntimeException
{
    /**
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     * @return void
     */
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
