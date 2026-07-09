<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 상각률 참조데이터에 시행연도(effective_year)를 도입한다.
 *
 * 세법 개정 시 동일 내용연수에 대해 연도별로 다른 상각률을 보관할 수 있도록
 * (useful_life) 단일 유니크를 (useful_life, effective_year) 복합 유니크로 교체한다.
 * 기존 행은 원본 표 기준연도인 2023을 시행연도로 채운다(as-of 조회 기준선).
 */
class AddEffectiveYearToDepreciationRates extends Migration
{
    private const BASELINE_YEAR = 2023;

    public function up(): void
    {
        $this->forge->addColumn('depreciation_rates', [
            'effective_year' => [
                'type'     => 'SMALLINT',
                'unsigned' => true,
                'null'     => false,
                'default'  => self::BASELINE_YEAR,
                'after'    => 'useful_life',
            ],
        ]);

        // 단일 유니크 → (내용연수, 시행연도) 복합 유니크로 교체
        $this->forge->dropKey('depreciation_rates', 'uniq_depreciation_rates_useful_life', false);
        $this->forge->addKey(['useful_life', 'effective_year'], false, true, 'uniq_depreciation_rates_life_year');
        $this->forge->processIndexes('depreciation_rates');
    }

    public function down(): void
    {
        $this->forge->dropKey('depreciation_rates', 'uniq_depreciation_rates_life_year', false);
        $this->forge->addKey('useful_life', false, true, 'uniq_depreciation_rates_useful_life');
        $this->forge->processIndexes('depreciation_rates');
        $this->forge->dropColumn('depreciation_rates', 'effective_year');
    }
}
