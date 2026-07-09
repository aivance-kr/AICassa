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
        $business  = ['name' => '내상회', 'biz_reg_no' => '1234567890', 'owner_name' => '홍길동'];
        $statement = [
            'year'                => 2024,
            'revenue_by_account'  => ['매출' => 10_000_000],
            'total_revenue'       => 10_000_000,
            'revenue_exclude'     => 0,
            'revenue_add'         => 0,
            'adjusted_revenue'    => 10_000_000,
            'expense_by_account'  => ['임차료' => 1_000_000],
            'inventory'           => ['goods_begin' => 0, 'goods_end' => 0, 'materials_begin' => 0, 'materials_end' => 0],
            'goods_cogs'          => 0,
            'materials_cost'      => 0,
            'manufacturing'       => ['materials' => 0, 'labor' => 0, 'overhead' => 0, 'total' => 0],
            'general_admin'       => [['name' => '임차료', 'amount' => 1_000_000]],
            'general_admin_total' => 1_000_000,
            'necessary_expense'   => 1_000_000,
            'expense_exclude'     => 0,
            'expense_add'         => 0,
            'adjusted_expense'    => 1_000_000,
            'pre_income'          => 9_000_000,
            'donation_over'       => 0,
            'donation_carryover'  => 0,
            'income_amount'       => 9_000_000,
        ];
        $depreciation = [
            ['name' => '노트북', 'method' => 'straight_line', 'acquisition_cost' => 10_000_000, 'depreciation' => 2_000_000, 'accumulated' => 2_000_000, 'book_value' => 8_000_000],
        ];

        $book = (new TaxFormExporter())->spreadsheet($business, $statement, $depreciation, 2024, '2024-v1');

        $this->assertSame(3, $book->getSheetCount());
        $this->assertSame('소득금액계산서', $book->getSheet(0)->getTitle());
        $this->assertSame('필요경비명세서', $book->getSheet(1)->getTitle());
        $this->assertSame('감가상각조정명세서', $book->getSheet(2)->getTitle());

        // 소득금액계산서: ⑪ 장부상 수입금액(B8) ~ ㉒ 당해연도 소득금액(B19)
        $income = $book->getSheet(0);
        $this->assertSame(10_000_000, $income->getCell('B8')->getValue());  // ⑪
        $this->assertSame(9_000_000, $income->getCell('B19')->getValue());  // ㉒

        // 감가상각조정명세서: 첫 자산 당기 상각비
        $this->assertSame('노트북', $book->getSheet(2)->getCell('A4')->getValue());
        $this->assertSame(2_000_000, $book->getSheet(2)->getCell('D4')->getValue());
    }
}
