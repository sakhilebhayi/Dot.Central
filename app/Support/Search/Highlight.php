<?php

namespace App\Support\Search;

use Illuminate\Support\HtmlString;

/** Wraps the searched words in <mark> (InfoDot's result highlight). The text is escaped first, so nothing a record holds becomes markup. */
final class Highlight
{
    /** @param  list<string>  $words */
    public static function mark(string $text, array $words): HtmlString
    {
        if ($words === []) {
            return new HtmlString(e($text));
        }

        $pattern = '/('.implode('|', array_map(fn (string $w): string => preg_quote($w, '/'), $words)).')/iu';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];
        $html = '';
        foreach ($parts as $part) {
            $isMatch = in_array(mb_strtolower($part), $words, true);
            $html .= $isMatch ? '<mark>'.e($part).'</mark>' : e($part);
        }

        return new HtmlString($html);
    }
}
