<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * 표준산업분류 업종코드(참조 데이터).
 * useful_life: 업종 연계 기준 내용연수(감가상각 기본값 산정용).
 * 전체 1,700여 건은 별도 임포트 작업으로 채운다.
 */
class CreateIndustryCodes extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'code'        => ['type' => 'VARCHAR', 'constraint' => 20],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'useful_life' => ['type' => 'INT', 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code', 'uniq_industry_codes_code');
        $this->forge->createTable('industry_codes', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('industry_codes', true);
    }
}
