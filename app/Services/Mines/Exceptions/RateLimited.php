<?php

namespace App\Services\Mines\Exceptions;

/** 429: Dot.Mines asked us to wait. */
class RateLimited extends MinesException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Dot.Mines rate limit reached; retry after '.$retryAfter.' s.', 429);
    }
}
