<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Smalot\PdfParser\Parser;

/**
 * Kept as its own small, focused class -- separate from
 * AgentKnowledgeController -- specifically so tests can stub the
 * successful-extraction path (Mockery) without needing a hand-crafted,
 * unverifiable PDF byte fixture, while the real failure path (a
 * genuinely invalid file) still exercises this actual class directly.
 */
class PdfTextExtractor
{
    /**
     * @throws \RuntimeException if the file can't be parsed, or parses
     *                           to no real text (e.g. a scanned/image-only PDF with no text layer)
     */
    public function extract(UploadedFile $file): string
    {
        try {
            $pdf = (new Parser)->parseFile($file->getRealPath());
            $text = trim($pdf->getText());
        } catch (\Throwable $e) {
            throw new \RuntimeException('This file could not be read as a PDF.', previous: $e);
        }

        if ($text === '') {
            throw new \RuntimeException('No extractable text was found in this PDF (it may be a scanned image with no text layer).');
        }

        return $text;
    }
}
