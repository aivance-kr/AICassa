<?php

use App\Services\TaxFormExporter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 신고서식 엑셀 생성 — 시트 구성·값 검증(순수, DB 불필요).
 *
 * @internal
 */
final class TaxFormExporterTest extends CIUnitTestCase
{
    public function testSpreadsheetHasThreeSheetsWithData(): void
    {
        $business  = ['name' => '내상회', 'biz_reg_no' => '1234567890'];
        $statement = [
            'year'               => 2024,
            'revenue_by_account' => ['매출' => 10_000_000],
            'total_revenue'      => 10_000_000,
            'expense_by_account' => ['상품매입' => 4_000_000, '임차료' => 1_000_000],
            'inventory'          => ['goods_begin' => 500_000, 'goods_end' => 1_500_000, 'materials_begin' => 0, 'materials_end' => 0],
            'goods_cogs'         => 3_000_000,
            'materials_cost'     => 0,
            'necessary_expense'  => 4_000_000,
            'income_amount'      => 6_000_000,
        ];
        $depreciation = [
            ['name' => '노트북', 'method' => 'straight_line', 'acquisition_cost' => 10_000_000, 'depreciation' => 2_000_000, 'accumulated' => 2_000_000, 'book_value' => 8_000_000],
        ];

        $book = (new TaxFormExporter())->spreadsheet($business, $statement, $depreciation, 2024);

        $this->assertSame(3, $book->getSheetCount());
        $this->assertSame('소득금액계산서', $book->getSheet(0)->getTitle());
        $this->assertSame('필요경비명세서', $book->getSheet(1)->getTitle());
        $this->assertSame('감가상각조정명세서', $book->getSheet(2)->getTitle());

        // 소득금액계산서: 소득금액 값(6번째 행 B열부터 총수입/필요경비/소득금액)
        $income = $book->getSheet(0);
        $this->assertSame(10_000_000, $income->getCell('B6')->getValue()); // 총수입금액
        $this->assertSame(6_000_000, $income->getCell('B8')->getValue());  // 소득금액

        // 감가상각조정명세서: 첫 자산 당기 상각비
        $this->assertSame('노트북', $book->getSheet(2)->getCell('A4')->getValue());
        $this->assertSame(2_000_000, $book->getSheet(2)->getCell('D4')->getValue());
    }
}
