<?php

namespace App\Exceptions;

use Exception;

class ResourceNotFoundException extends Exception
{
    /**
     * Create a new exception instance.
     *
     * @param  string  $message  The exception message
     * @param  int  $code  The exception code (default: 404)
     */
    public function __construct(string $message = 'Resource not found', int $code = 404)
    {
        parent::__construct($message, $code);
    }

    /**
     * Get the HTTP status code.
     */
    public function getStatusCode(): int
    {
        return $this->code;
    }
}
