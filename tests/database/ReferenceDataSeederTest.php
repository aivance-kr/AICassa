<?php

use App\Database\Seeds\ReferenceDataSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 마이그레이션 + 참조 시드 검증.
 * 전체 마이그레이션 실행 후 계정과목/상각률 시드 데이터를 확인한다.
 *
 * @internal
 */
final class ReferenceDataSeederTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    // null = 모든 네임스페이스(App 포함)의 마이그레이션 실행
    protected $namespace;
    protected $seed = ReferenceDataSeeder::class;

    /**
     * 핵심 테이블이 모두 생성되었는지.
     */
    public function testCoreTablesExist(): void
    {
        $tables = [
            'users', 'businesses', 'partners', 'accounts',
            'ledger_entries', 'assets', 'inventories',
            'depreciation_rates', 'industry_codes',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(
                $this->db->tableExists($table),
                "테이블 {$table} 이(가) 존재해야 한다",
            );
        }
    }

    /**
     * 상각률 59개 행(내용연수 2~60)과 대표값 검증.
     */
    public function testDepreciationRatesSeeded(): void
    {
        $count = $this->db->table('depreciation_rates')->countAllResults();
        $this->assertSame(59, $count);

        $row = $this->db->table('depreciation_rates')
            ->where('useful_life', 5)
            ->get()
            ->getRowArray();

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(0.2, (float) $row['straight_line_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.451, (float) $row['declining_balance_rate'], 0.0001);
    }

    /**
     * 계정과목 카테고리별 개수 검증.
     */
    public function testAccountsSeeded(): void
    {
        $builder = $this->db->table('accounts');

        $this->assertSame(26, $builder->countAllResults(false));
        $this->assertSame(2, $this->db->table('accounts')->where('category', 'income')->countAllResults());
        $this->assertSame(19, $this->db->table('accounts')->where('category', 'expense')->countAllResults());
        $this->assertSame(5, $this->db->table('accounts')->where('category', 'asset')->countAllResults());
        $this->assertSame(3, $this->db->table('accounts')->where('is_manufacturing', 1)->countAllResults());
    }
}
