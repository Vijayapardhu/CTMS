<?php

namespace App\Exceptions;

use Exception;

class ValidationException extends Exception
{
    /**
     * The validation errors.
     */
    protected array $errors = [];

    /**
     * Create a new exception instance.
     *
     * @param  string  $message  The exception message
     * @param  array  $errors  The validation errors
     * @param  int  $code  The exception code (default: 422)
     */
    public function __construct(string $message = 'Validation failed', array $errors = [], int $code = 422)
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }

    /**
     * Get the HTTP status code.
     */
    public function getStatusCode(): int
    {
        return $this->code;
    }

    /**
     * Get the validation errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
