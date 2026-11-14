<?php

namespace App\Support\Search;

/** One row of the header search: a stored record and the page that already shows it. */
final class SearchHit
{
    public function __construct(
        public readonly string $group,
        public readonly string $title,
        public readonly ?string $sub,
        public readonly string $url,
        public readonly ?string $ref,
        /** a short word for the dropdown's right edge (type, severity, status); the full context is `sub` */
        public readonly ?string $tag = null,
    ) {}
}
