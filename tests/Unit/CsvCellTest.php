<?php

namespace Tests\Unit;

use App\Support\Csv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvCellTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: mixed}> */
    public static function cells(): array
    {
        return [
            'equals' => ['=1+1', "'=1+1"],
            'plus word' => ['+cmd', "'+cmd"],
            'minus word' => ['-cmd', "'-cmd"],
            'at' => ['@SUM(A1)', "'@SUM(A1)"],
            'tab' => ["\t=1", "'\t=1"],
            'carriage return' => ["\r=1", "'\r=1"],
            'plain text' => ['Moved to Face 2', 'Moved to Face 2'],
            'negative number string' => ['-12.5', '-12.5'],
            'int' => [-3, -3],
            'float' => [1.5, 1.5],
            'null' => [null, null],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('cells')]
    public function test_cell_is_neutralised_only_when_it_could_be_a_formula(mixed $in, mixed $out): void
    {
        $this->assertSame($out, Csv::cell($in));
    }

    public function test_row_maps_every_cell(): void
    {
        $this->assertSame(["'=x", 'ok', 4], Csv::row(['=x', 'ok', 4]));
    }
}
