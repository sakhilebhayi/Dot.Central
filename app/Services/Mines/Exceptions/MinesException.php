<?php

namespace App\Services\Mines\Exceptions;

use RuntimeException;

/**
 * Base class for every failure talking to Dot.Mines. Messages are safe to
 * store and display: they never carry the token, the signature or a body.
 */
class MinesException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
