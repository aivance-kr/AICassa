<?php

use App\Libraries\SpreadsheetReader;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class SpreadsheetReaderTest extends CIUnitTestCase
{
    public function testRejectsCsvLineLongerThanLimit(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'aicassa-csv-');
        $this->assertNotFalse($path);
        file_put_contents($path, str_repeat('a', 64 * 1024 + 1));

        try {
            $this->expectException(RuntimeException::class);
            (new SpreadsheetReader())->read($path, 'csv');
        } finally {
            unlink($path);
        }
    }

    public function testReadsNormalCsvWithStreamingParser(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'aicassa-csv-');
        $this->assertNotFalse($path);
        file_put_contents($path, "날짜,금액\n2026-01-01,1000\n");

        try {
            $grid = (new SpreadsheetReader())->read($path, 'csv');
        } finally {
            unlink($path);
        }

        $this->assertSame([['날짜', '금액'], ['2026-01-01', '1000']], $grid);
    }
}
