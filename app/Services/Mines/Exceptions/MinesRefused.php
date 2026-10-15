<?php

namespace App\Services\Mines\Exceptions;

/**
 * Dot.Mines understood the request and refused it on its own rules
 * (HTTP 422): for an operator assignment, the eligibility blockers.
 */
class MinesRefused extends MinesException
{
    /** @param list<string> $messages */
    public function __construct(public readonly array $messages, int $code = 422)
    {
        parent::__construct('Dot.Mines refused: '.implode(' ', $messages), $code);
    }
}
