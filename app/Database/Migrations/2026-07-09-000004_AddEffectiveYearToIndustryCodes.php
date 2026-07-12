<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 업종코드 참조데이터에 시행연도(effective_year)를 도입한다.
 *
 * 업종별 기준 내용연수가 개정되면 동일 코드에 대해 연도별로 다른 값을 보관할 수
 * 있도록 (code) 단일 유니크를 (code, effective_year) 복합 유니크로 교체한다.
 * 기존 행은 원본 연계표 기준연도인 2023을 시행연도로 채운다(as-of 조회 기준선).
 */
class AddEffectiveYearToIndustryCodes extends Migration
{
    private const BASELINE_YEAR = 2023;

    public function up(): void
    {
        $this->forge->addColumn('industry_codes', [
            'effective_year' => [
                'type'     => 'SMALLINT',
                'unsigned' => true,
                'null'     => false,
                'default'  => self::BASELINE_YEAR,
                'after'    => 'code',
            ],
        ]);

        // 단일 유니크 → (코드, 시행연도) 복합 유니크로 교체
        $this->forge->dropKey('industry_codes', 'uniq_industry_codes_code', false);
        $this->forge->addKey(['code', 'effective_year'], false, true, 'uniq_industry_codes_code_year');
        $this->forge->processIndexes('industry_codes');
    }

    public function down(): void
    {
        $this->forge->dropKey('industry_codes', 'uniq_industry_codes_code_year', false);
        $this->forge->addKey('code', false, true, 'uniq_industry_codes_code');
        $this->forge->processIndexes('industry_codes');
        $this->forge->dropColumn('industry_codes', 'effective_year');
    }
}
