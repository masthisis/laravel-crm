<?php

namespace App\Services\Leads;

use RuntimeException;

/**
 * Thrown when a file with the same SHA-1 was already imported into the
 * source and no explicit confirmation was given (spec 05 §6).
 */
class DuplicateFileException extends RuntimeException
{
    public function __construct(public readonly string $hash)
    {
        parent::__construct('This file was already imported into the source (SHA-1 '.$hash.')');
    }
}
