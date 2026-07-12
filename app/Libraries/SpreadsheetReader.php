<?php

namespace App\Libraries;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

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
     * 읽을 최대 행 수(헤더 포함). 과대 파일 방어.
     */
    private const MAX_ROWS = 10000;

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
        return match ($extension) {
            'xls', 'xlsx' => $this->readSpreadsheet($absolutePath),
            default       => $this->readCsv($absolutePath),
        };
    }

    /**
     * @return list<list<string>>
     */
    private function readCsv(string $absolutePath): array
    {
        $raw = @file_get_contents($absolutePath);
        if ($raw === false) {
            throw new RuntimeException('파일을 읽을 수 없습니다.');
        }

        $utf8  = mb_check_encoding($raw, 'UTF-8') ? $raw : (string) mb_convert_encoding($raw, 'UTF-8', 'CP949');
        $lines = preg_split('/\r\n|\r|\n/', trim($utf8)) ?: [];

        $grid = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols   = str_getcsv($line);
            $grid[] = array_map(static fn ($c): string => trim((string) $c), $cols);

            if (count($grid) >= self::MAX_ROWS) {
                break;
            }
        }

        return $grid;
    }

    /**
     * @return list<list<string>>
     */
    private function readSpreadsheet(string $absolutePath): array
    {
        try {
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

        return $grid;
    }
}
