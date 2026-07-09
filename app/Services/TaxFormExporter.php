<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 종합소득세 신고서식 3종을 엑셀(Spreadsheet)로 생성한다.
 * 데이터는 TaxFormService 산출물을 그대로 받는다(계산 책임 없음).
 */
final class TaxFormExporter
{
    private const METHOD_LABELS = [
        'straight_line'     => '정액법',
        'declining_balance' => '정률법',
    ];

    /**
     * @param array<string, mixed>                                                                                                  $business
     * @param array<string, mixed>                                                                                                  $statement    TaxFormService::incomeStatement
     * @param list<array{name:string, method:string|null, acquisition_cost:int, depreciation:int, accumulated:int, book_value:int}> $depreciation
     */
    public function spreadsheet(array $business, array $statement, array $depreciation, int $year): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getProperties()
            ->setTitle('종합소득세 신고서식')
            ->setCreator('AICassa');

        $this->buildIncomeStatement($book->getActiveSheet(), $business, $statement, $year);
        $this->buildExpenseDetail($book->createSheet(), $statement, $year);
        $this->buildDepreciation($book->createSheet(), $depreciation, $year);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * ① 간편장부 소득금액계산서.
     *
     * @param array<string, mixed> $business
     * @param array<string, mixed> $statement
     */
    private function buildIncomeStatement(Worksheet $sheet, array $business, array $statement, int $year): void
    {
        $sheet->setTitle('소득금액계산서');
        $sheet->setCellValue('A1', "간편장부 소득금액계산서 ({$year} 귀속)");
        $sheet->mergeCells('A1:B1');

        $sheet->setCellValue('A3', '상호');
        $sheet->setCellValue('B3', (string) $business['name']);
        $sheet->setCellValue('A4', '사업자등록번호');
        $sheet->setCellValue('B4', (string) ($business['biz_reg_no'] ?? ''));

        $rows = [
            ['총수입금액', $statement['total_revenue']],
            ['필요경비', $statement['necessary_expense']],
            ['소득금액 (총수입금액 − 필요경비)', $statement['income_amount']],
        ];
        $r = 6;

        foreach ($rows as [$label, $value]) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $value);
            $r++;
        }

        $this->formatMoneyColumn($sheet, 'B', 6, $r - 1);
        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(18);
    }

    /**
     * ② 총수입금액 및 필요경비명세서.
     *
     * @param array<string, mixed> $statement
     */
    private function buildExpenseDetail(Worksheet $sheet, array $statement, int $year): void
    {
        $sheet->setTitle('필요경비명세서');
        $sheet->setCellValue('A1', "총수입금액 및 필요경비명세서 ({$year} 귀속)");
        $sheet->mergeCells('A1:B1');

        $r = 3;
        $sheet->setCellValue("A{$r}", '[수입]');
        $r++;
        $start = $r;
        /** @var array<string, int> $revenue */
        $revenue = $statement['revenue_by_account'];

        foreach ($revenue as $name => $amt) {
            $sheet->setCellValue("A{$r}", $name);
            $sheet->setCellValue("B{$r}", $amt);
            $r++;
        }
        $sheet->setCellValue("A{$r}", '수입 계');
        $sheet->setCellValue("B{$r}", $statement['total_revenue']);
        $this->formatMoneyColumn($sheet, 'B', $start, $r);

        $r += 2;
        $sheet->setCellValue("A{$r}", '[필요경비]');
        $r++;
        $eStart = $r;
        $sheet->setCellValue("A{$r}", '매출원가(상품)');
        $sheet->setCellValue("B{$r}", $statement['goods_cogs']);
        $r++;
        if ($statement['materials_cost'] !== 0) {
            $sheet->setCellValue("A{$r}", '재료비');
            $sheet->setCellValue("B{$r}", $statement['materials_cost']);
            $r++;
        }
        /** @var array<string, int> $expense */
        $expense = $statement['expense_by_account'];

        foreach ($expense as $name => $amt) {
            if ($name === '상품매입' || $name === '재료매입') {
                continue; // 매출원가/재료비로 대체
            }
            $sheet->setCellValue("A{$r}", $name);
            $sheet->setCellValue("B{$r}", $amt);
            $r++;
        }
        $sheet->setCellValue("A{$r}", '필요경비 계');
        $sheet->setCellValue("B{$r}", $statement['necessary_expense']);
        $this->formatMoneyColumn($sheet, 'B', $eStart, $r);

        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(18);
    }

    /**
     * ③ 감가상각비 조정명세서.
     *
     * @param list<array{name:string, method:string|null, acquisition_cost:int, depreciation:int, accumulated:int, book_value:int}> $depreciation
     */
    private function buildDepreciation(Worksheet $sheet, array $depreciation, int $year): void
    {
        $sheet->setTitle('감가상각조정명세서');
        $sheet->setCellValue('A1', "감가상각비 조정명세서 ({$year} 귀속)");
        $sheet->mergeCells('A1:F1');

        $headers = ['자산명', '상각방법', '취득금액', '당기 감가상각비', '감가상각누계액', '기말 장부가액'];
        $cols    = ['A', 'B', 'C', 'D', 'E', 'F'];

        foreach ($headers as $i => $h) {
            $sheet->setCellValue("{$cols[$i]}3", $h);
        }

        $r = 4;

        foreach ($depreciation as $d) {
            $sheet->setCellValue("A{$r}", $d['name']);
            $sheet->setCellValue("B{$r}", self::METHOD_LABELS[$d['method']] ?? '-');
            $sheet->setCellValue("C{$r}", $d['acquisition_cost']);
            $sheet->setCellValue("D{$r}", $d['depreciation']);
            $sheet->setCellValue("E{$r}", $d['accumulated']);
            $sheet->setCellValue("F{$r}", $d['book_value']);
            $r++;
        }

        if ($r > 4) {
            foreach (['C', 'D', 'E', 'F'] as $c) {
                $this->formatMoneyColumn($sheet, $c, 4, $r - 1);
            }
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $c) {
            $sheet->getColumnDimension($c)->setWidth(16);
        }
    }

    /**
     * 지정 열/행 범위를 천단위 콤마 + 우측정렬로 서식 적용.
     */
    private function formatMoneyColumn(Worksheet $sheet, string $column, int $from, int $to): void
    {
        $range = "{$column}{$from}:{$column}{$to}";
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
}
