<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An AI call that failed. Retryable failures (rate limits, provider errors, timeouts) can be tried on a fallback
 * model; others (a bad key, a bad request) can't be fixed by trying again.
 */
class LlmException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
