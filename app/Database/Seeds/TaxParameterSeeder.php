<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * 연도별 세법 파라미터 초기값(운영자 관리 대상).
 *
 * 시행연도 기준선(2023)을 시드한다. 스칼라 룰 3종(vat_divisor·memorandum_value·
 * declining_residual_divisor)은 TaxRuleResolver 가 소비해 부가세·감가상각 계산에 반영되며,
 * 값은 Config\TaxRules 기준선과 동일하다. 소득세 누진구간·경비율표(JSON)는 운영자 관리용으로
 * 함께 시드하되 현재 계산에는 미배선(갭)이다.
 *
 * 세법 개정 시 운영자가 화면에서 새 시행연도를 추가(최신값 복제 후 수정)하면
 * as-of 규칙에 따라 해당 연도부터 새 값이 적용된다.
 *
 * 실행: php spark db:seed TaxParameterSeeder (ReferenceDataSeeder 에서 함께 호출)
 */
class TaxParameterSeeder extends Seeder
{
    private const BASELINE_YEAR = 2023;

    public function run(): void
    {
        $now  = date('Y-m-d H:i:s');
        $rows = [];

        foreach ($this->baseline() as $param) {
            $param['fiscal_year'] = self::BASELINE_YEAR;
            $param['created_at']  = $now;
            $param['updated_at']  = $now;
            $rows[]               = $param;
        }

        // 재실행 안전(멱등): 비우고 재삽입
        $this->db->table('tax_parameters')->emptyTable();
        $this->db->table('tax_parameters')->insertBatch($rows);
    }

    /**
     * 기준연도(2023) 파라미터 세트.
     *
     * @return list<array<string, mixed>>
     */
    private function baseline(): array
    {
        return [
            [
                'param_key'   => 'vat_divisor',
                'value_type'  => 'int',
                'param_value' => '10',
                'category'    => 'vat',
                'label'       => '부가세 제수',
                'unit'        => null,
                'sort_order'  => 10,
                'description' => '공급가액 ÷ 제수 = 부가세 (10 → 1/10 = 10%). 과세 증빙에만 적용.',
            ],
            [
                'param_key'   => 'memorandum_value',
                'value_type'  => 'int',
                'param_value' => '1000',
                'category'    => 'depreciation',
                'label'       => '비망가액',
                'unit'        => '원',
                'sort_order'  => 10,
                'description' => '자산을 완전상각하지 않고 장부에 남기는 최소 가액',
            ],
            [
                'param_key'   => 'declining_residual_divisor',
                'value_type'  => 'int',
                'param_value' => '20',
                'category'    => 'depreciation',
                'label'       => '정률법 잔존가액 제수',
                'unit'        => null,
                'sort_order'  => 20,
                'description' => '정률법 잔존가액 = 취득금액 ÷ 제수 (20 → 취득금액의 5%)',
            ],
            [
                'param_key'   => 'low_value_asset_threshold',
                'value_type'  => 'int',
                'param_value' => '1000000',
                'category'    => 'depreciation',
                'label'       => '소액자산 즉시비용 한도',
                'unit'        => '원',
                'sort_order'  => 30,
                'description' => '취득금액이 이 한도(거래단위) 이하이면 즉시비용(즉시상각) 처리 대상 후보',
            ],
            [
                'param_key'   => 'income_tax_brackets',
                'value_type'  => 'json',
                'param_value' => json_encode($this->incomeTaxBrackets(), JSON_UNESCAPED_UNICODE),
                'category'    => 'income_tax',
                'label'       => '종합소득세 누진세율 구간',
                'unit'        => null,
                'sort_order'  => 10,
                'description' => '{over: 과세표준 하한, rate: 세율, deduction: 누진공제액} 배열 (현재 계산 미배선·참고용)',
            ],
            [
                'param_key'   => 'simple_expense_rates',
                'value_type'  => 'json',
                'param_value' => '[]',
                'category'    => 'income_tax',
                'label'       => '단순경비율표(업종별)',
                'unit'        => null,
                'sort_order'  => 20,
                'description' => '업종코드별 단순경비율 (운영자 입력 대상·현재 계산 미배선)',
            ],
            [
                'param_key'   => 'standard_expense_rates',
                'value_type'  => 'json',
                'param_value' => '[]',
                'category'    => 'income_tax',
                'label'       => '기준경비율표(업종별)',
                'unit'        => null,
                'sort_order'  => 30,
                'description' => '업종코드별 기준경비율 (운영자 입력 대상·현재 계산 미배선)',
            ],
        ];
    }

    /**
     * 종합소득세 누진세율 구간(2023 개정 기준).
     *
     * @return list<array{over: int, rate: float, deduction: int}>
     */
    private function incomeTaxBrackets(): array
    {
        return [
            ['over' => 0, 'rate' => 0.06, 'deduction' => 0],
            ['over' => 14_000_000, 'rate' => 0.15, 'deduction' => 1_260_000],
            ['over' => 50_000_000, 'rate' => 0.24, 'deduction' => 5_760_000],
            ['over' => 88_000_000, 'rate' => 0.35, 'deduction' => 15_440_000],
            ['over' => 150_000_000, 'rate' => 0.38, 'deduction' => 19_940_000],
            ['over' => 300_000_000, 'rate' => 0.40, 'deduction' => 25_940_000],
            ['over' => 500_000_000, 'rate' => 0.42, 'deduction' => 35_940_000],
            ['over' => 1_000_000_000, 'rate' => 0.45, 'deduction' => 65_940_000],
        ];
    }
}
