<?php

namespace App\Exceptions;

use Exception;

/**
 * The request was well-formed and the caller was permitted, but it conflicts
 * with the current state of the system — an illegal state transition, a double
 * booking, a capacity breach.
 *
 * This is a 409, not a 422: the payload is not invalid, the world is.
 */
class BusinessRuleException extends Exception
{
    /**
     * @param  array<string, mixed>  $context  Extra detail safe to return to the client.
     */
    public function __construct(
        string $message = 'This operation conflicts with the current state.',
        protected array $context = [],
        int $code = 409,
    ) {
        parent::__construct($message, $code);
    }

    public function getStatusCode(): int
    {
        return $this->code;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Convenience constructor for a rejected state transition.
     */
    public static function invalidTransition(string $subject, string $from, string $to): self
    {
        return new self(
            "Cannot change {$subject} status from {$from} to {$to}.",
            ['from' => $from, 'to' => $to],
        );
    }
}
