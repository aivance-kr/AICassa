<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 연도별 세법 파라미터(운영자 관리). 귀속연도 × 파라미터키 범용 키-값 저장소.
 *
 * 부가세율·감가상각 비망가액/잔존율·신고서식 버전 등 코드에 흩어져 있던 상수와,
 * 소득세 누진구간·경비율표 같은 복합 세율표(JSON)를 한 테이블에서 연도별로 관리한다.
 * 특정 연도 미등록 시 그 이하 가장 최근 연도값을 적용(effective-from).
 */
class CreateTaxParameters extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'fiscal_year' => ['type' => 'SMALLINT', 'unsigned' => true], // 귀속연도
            'param_key'   => ['type' => 'VARCHAR', 'constraint' => 64],  // 파라미터 식별키
            'value_type'  => ['type' => 'VARCHAR', 'constraint' => 16],  // int|decimal|string|json
            'param_value' => ['type' => 'TEXT'],                          // 스칼라 문자열 또는 JSON
            'category'    => ['type' => 'VARCHAR', 'constraint' => 32],  // vat|depreciation|income_tax|form
            'label'       => ['type' => 'VARCHAR', 'constraint' => 128], // 화면 표시명
            'unit'        => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true], // %,원 등
            'sort_order'  => ['type' => 'INT', 'default' => 0],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['fiscal_year', 'param_key'], 'uniq_tax_parameters_year_key');
        $this->forge->addKey(['param_key', 'fiscal_year'], false, false, 'idx_tax_parameters_key_year');
        $this->forge->createTable('tax_parameters', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('tax_parameters', true);
    }
}
