<?php

namespace App\Libraries;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * 업로드 파일(CSV/TXT · xlsx/xls)을 통일된 문자열 그리드로 읽는다.
 *
 * - CSV/TXT: CP949(엑셀 한글 CSV) 자동 감지 후 UTF-8 로 변환해 파싱한다.
 * - xlsx/xls: PhpSpreadsheet 로 첫 시트를 읽되, 날짜 serial 을 피하기 위해
 *   셀의 "표시값"(getFormattedValue)을 문자열로 취한다.
 *
 * 반환 그리드는 list<list<string>> 형태(행 → 셀). 계산·검증 책임은 없다.
 */
final class SpreadsheetReader
{
    /**
     * 최대 업로드 바이트.
     */
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    /**
     * 읽을 최대 행 수(헤더 포함). 과대 파일 방어.
     */
    private const MAX_ROWS = 10000;

    private const MAX_COLUMNS                 = 64;
    private const MAX_CSV_LINE_BYTES          = 64 * 1024;
    private const MAX_XLSX_ENTRIES            = 10000;
    private const MAX_XLSX_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;
    private const MAX_XLSX_COMPRESSION_RATIO  = 100;

    /**
     * 확장자에 맞춰 그리드를 읽는다.
     *
     * @param string $absolutePath 서버 임시 파일 절대경로
     * @param string $extension    소문자 확장자(csv|txt|xls|xlsx)
     *
     * @return list<list<string>>
     *
     * @throws RuntimeException 파일을 읽을 수 없을 때
     */
    public function read(string $absolutePath, string $extension): array
    {
        $this->assertFileSize($absolutePath);

        return match ($extension) {
            'xlsx'  => $this->readSpreadsheet($absolutePath, true),
            'xls'   => $this->readSpreadsheet($absolutePath, false),
            default => $this->readCsv($absolutePath),
        };
    }

    /**
     * @throws RuntimeException
     */
    private function assertFileSize(string $absolutePath): void
    {
        $size = filesize($absolutePath);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw new RuntimeException('업로드 파일은 10MB 이하만 허용됩니다.');
        }
    }

    /**
     * @return list<list<string>>
     */
    private function readCsv(string $absolutePath): array
    {
        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('파일을 읽을 수 없습니다.');
        }

        $grid = [];

        try {
            while (($line = fgets($handle, self::MAX_CSV_LINE_BYTES + 2)) !== false) {
                if (strlen($line) > self::MAX_CSV_LINE_BYTES) {
                    throw new RuntimeException('CSV 한 행은 64KB 이하만 허용됩니다.');
                }
                $line = mb_check_encoding($line, 'UTF-8') ? $line : (string) mb_convert_encoding($line, 'UTF-8', 'CP949');
                if (trim($line) === '') {
                    continue;
                }
                $cols = str_getcsv($line);
                if (count($cols) > self::MAX_COLUMNS) {
                    throw new RuntimeException('CSV 열 수는 64개 이하만 허용됩니다.');
                }
                $grid[] = array_map(static fn ($cell): string => trim((string) $cell), $cols);

                if (count($grid) >= self::MAX_ROWS) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        return $grid;
    }

    /**
     * @return list<list<string>>
     */
    private function readSpreadsheet(string $absolutePath, bool $isXlsx): array
    {
        try {
            if ($isXlsx) {
                $this->assertSafeXlsxArchive($absolutePath);
            }
            $reader = IOFactory::createReaderForFile($absolutePath);
            $reader->setReadDataOnly(false); // 날짜 등 표시서식이 필요하므로 서식 포함 로드
            $book  = $reader->load($absolutePath);
            $sheet = $book->getSheet(0);
        } catch (Throwable $e) {
            log_message('error', '엑셀 파일 읽기 실패: {msg}', ['msg' => $e->getMessage()]);

            throw new RuntimeException('엑셀 파일을 읽을 수 없습니다.', 0, $e);
        }

        $highestRow = min($sheet->getHighestDataRow(), self::MAX_ROWS);
        $highestCol = $sheet->getHighestDataColumn();
        $colCount   = Coordinate::columnIndexFromString($highestCol);
        if ($colCount > self::MAX_COLUMNS) {
            $book->disconnectWorksheets();

            throw new RuntimeException('엑셀 열 수는 64개 이하만 허용됩니다.');
        }

        $grid = [];

        for ($row = 1; $row <= $highestRow; $row++) {
            $cells = [];

            for ($col = 1; $col <= $colCount; $col++) {
                $cells[] = trim((string) $sheet->getCell([$col, $row])->getFormattedValue());
            }

            // 완전히 빈 행은 건너뛴다.
            if (implode('', $cells) === '') {
                continue;
            }
            $grid[] = $cells;
        }

        $book->disconnectWorksheets();

        return $grid;
    }

    /**
     * XLSX ZIP 컨테이너의 엔트리·비압축 크기·압축률을 먼저 제한한다.
     *
     * @throws RuntimeException
     */
    private function assertSafeXlsxArchive(string $absolutePath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            throw new RuntimeException('유효한 XLSX 파일이 아닙니다.');
        }

        try {
            if ($zip->numFiles > self::MAX_XLSX_ENTRIES) {
                throw new RuntimeException('엑셀 압축 파일의 항목 수가 너무 많습니다.');
            }

            $uncompressedBytes = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new RuntimeException('엑셀 압축 파일을 검사할 수 없습니다.');
                }
                $size           = $stat['size'];
                $compressedSize = $stat['comp_size'];
                if ($size > self::MAX_XLSX_UNCOMPRESSED_BYTES
                    || ($compressedSize > 0 && $size / $compressedSize > self::MAX_XLSX_COMPRESSION_RATIO)) {
                    throw new RuntimeException('엑셀 압축 해제 크기 또는 압축률이 허용 범위를 초과했습니다.');
                }
                $uncompressedBytes += $size;
                if ($uncompressedBytes > self::MAX_XLSX_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException('엑셀 압축 해제 총 크기가 허용 범위를 초과했습니다.');
                }
            }
        } finally {
            $zip->close();
        }
    }
}
