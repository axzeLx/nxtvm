<?php

namespace App\Services\Collector;

use RuntimeException;

class CollectorException extends RuntimeException
{
    /** auth | tls | network | timeout | usage | error */
    public function __construct(string $message, public readonly string $kind = 'error')
    {
        parent::__construct($message);
    }
}
