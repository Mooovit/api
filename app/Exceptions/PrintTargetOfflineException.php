<?php

namespace App\Exceptions;

use Exception;

/**
 * API-039: thrown by App\Support\PrintQueue::enqueue() when a push names a
 * target printer but no live print station on the team currently serves it.
 * Controllers map this to 409 with the printer name in the error message so
 * the app learns WHY the push failed.
 */
class PrintTargetOfflineException extends Exception
{
    /**
     * @param string $printerName the demanded (not-live) printer name
     */
    public static function forPrinter(string $printerName): self
    {
        return new self('Printer "' . $printerName . '" is not available');
    }
}
