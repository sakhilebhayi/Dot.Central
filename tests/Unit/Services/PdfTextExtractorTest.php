<?php

namespace Tests\Unit\Services;

use App\Services\PdfTextExtractor;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PdfTextExtractorTest extends TestCase
{
    public function test_a_file_that_is_not_a_real_pdf_is_rejected_with_a_plain_language_message(): void
    {
        $file = UploadedFile::fake()->create('not-a-real.pdf', 10, 'application/pdf');
        // UploadedFile::fake()->create() writes arbitrary placeholder
        // bytes, not a real PDF structure -- this is a genuinely invalid
        // file, exercising the real failure path with no fixture needed.

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This file could not be read as a PDF.');

        (new PdfTextExtractor)->extract($file);
    }
}
